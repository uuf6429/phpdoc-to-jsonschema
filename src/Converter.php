<?php

namespace uuf6429\PHPDocToJSONSchema;

use ArrayObject;
use Exception;
use InvalidArgumentException;
use LogicException;
use phpDocumentor\Reflection;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Swaggest\JsonSchema;
use Throwable;

class Converter
{
    /**
     * Note: this naive approach (instead of e.g. unicode category) is intentional - it matches the original
     * implementation in Psalm.
     * @see https://github.com/vimeo/psalm/issues/11022
     */
    private const LC_STRING_PATTERN = '/^[^A-Z]*$/';

    /**
     * @see https://stackoverflow.com/a/13340826/314056
     * @see https://github.com/php/php-src/blob/df12ffcc77bfb9d14db9ae11c645798d8c0083a0/Zend/zend_operators.c#L3522
     */
    private const NUMERIC_STRING_PATTERN = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/';

    /**
     * A map from PHP built-in and alias types, to JsonSchema types.
     */
    private const NATIVE_TYPE_MAP = [
        'null' => 'null',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'int' => 'integer',
        'integer' => 'integer',
        'double' => 'number',
        'float' => 'number',
        'string' => 'string',
    ];

    /**
     * A map from virtual types, to JsonSchema types.
     */
    private const VIRTUAL_TYPE_MAP = [
        Reflection\Types\Null_::class => 'null',
        Reflection\Types\Integer::class => 'integer',
        Reflection\Types\Float_::class => 'number',
        Reflection\Types\Boolean::class => 'boolean',
        Reflection\Types\String_::class => 'string',
    ];

    /**
     * @param class-string|null $currentClass Fully-qualified class name of where the type occurred, when applicable.
     * @throws Throwable
     */
    public function convertTag(Reflection\DocBlock\Tags\TagWithType $tag, ?string $currentClass): JsonSchema\Schema
    {
        $type = $tag->getType() ?? throw new InvalidArgumentException('Tag does not have a type');

        $result = $this->convertType($type, $currentClass);
        $this->applyTitleAndDescription($result, (string)($tag->getDescription() ?? ''));

        if ($tag->getName() === 'property-read') {
            $result->offsetSet('readOnly', true);
        }

        if ($tag->getName() === 'property-write') {
            $result->offsetSet('writeOnly', true);
        }

        return $result;
    }

    /**
     * @param class-string|null $currentClass Fully-qualified class name of where the type occurred, when applicable.
     * @throws Throwable
     */
    public function convertType(Reflection\Type $type, ?string $currentClass): JsonSchema\Schema
    {
        $definitions = new ArrayObject();

        $result = $this->convertVirtualType($type, $currentClass, [], $definitions);

        if ($definitions->count()) {
            /**
             * @see https://github.com/swaggest/php-json-schema/issues/162
             * @phpstan-ignore-next-line
             */
            $result->definitions = (object)array_merge((array)($result->definitions ?? []), (array)$definitions);
        }

        return $result;
    }

    /**
     * @param null|class-string $currentClass
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    public function doConvertTag(Reflection\DocBlock\Tags\TagWithType $tag, ?string $currentClass, ArrayObject $definitions): JsonSchema\Schema
    {
        $type = $tag->getType() ?? throw new InvalidArgumentException('Tag does not have a type');

        $result = $this->convertVirtualType($type, $currentClass, [], $definitions);
        $this->applyTitleAndDescription($result, (string)($tag->getDescription() ?? ''));

        if ($tag->getName() === 'property-read') {
            $result->offsetSet('readOnly', true);
        }

        if ($tag->getName() === 'property-write') {
            $result->offsetSet('writeOnly', true);
        }

        return $result;
    }

    /**
     * @param class-string|null $currentClass
     * @param array<string, mixed> $options
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    private function convertVirtualType(Reflection\Type $type, ?string $currentClass, array $options, ArrayObject $definitions): JsonSchema\Schema
    {
        switch (true) {
            // We don't care about the following interfaces; they're already handled:
            // - Reflection\PseudoType
            // - Reflection\Types\AbstractList
            // - Reflection\Types\AggregatedType
            // - Reflection\PseudoTypes\TraitString
            // - Reflection\PseudoTypes\HtmlEscapedString
            // - Reflection\PseudoTypes\LiteralString      Meant for security-oriented is_literal, which AFAIK has no counterpart in JSON Schema

            case $type instanceof Reflection\Types\ArrayKey:
                throw new LogicException('Array key type cannot be converted to JSON Schema (this case should not have been reached)');

            case $type instanceof Reflection\Types\ClassString:
            case $type instanceof Reflection\Types\InterfaceString:
                throw new Exception('TODO'); // TODO

            case $type instanceof Reflection\PseudoTypes\ArrayShape:
                throw new Exception('TODO'); // TODO

            case $type instanceof Reflection\PseudoTypes\CallableString:
                throw new Exception('TODO'); // TODO should we simply return the string, at most with some simple pattern matching?

            case $type instanceof Reflection\PseudoTypes\ConstExpression:
                throw new LogicException('Const expression cannot be converted to JSON Schema'); // TODO actually I think we could/should...needs investigation

            case $type instanceof Reflection\PseudoTypes\False_:
                return $this->createSchema(['const' => false, ...$options]);

            case $type instanceof Reflection\PseudoTypes\FloatValue:
                return $this->createSchema(['const' => $type->getValue(), ...$options]);

            case $type instanceof Reflection\PseudoTypes\IntegerRange:
                return $this->createSchema(['type' => 'integer', 'minimum' => $type->getMinValue(), 'maximum' => $type->getMaxValue(), ...$options]);

            case $type instanceof Reflection\PseudoTypes\IntegerValue:
                return $this->createSchema(['const' => $type->getValue(), ...$options]);

            case $type instanceof Reflection\PseudoTypes\List_:
                return $this->createSchema(['type' => 'array', 'items' => $this->convertVirtualType($type->getValueType(), $currentClass, [], $definitions), ...$options]);

            case $type instanceof Reflection\PseudoTypes\LowercaseString:
                return $this->createSchema(['type' => 'string', 'pattern' => self::LC_STRING_PATTERN, ...$options]);

            case $type instanceof Reflection\PseudoTypes\NegativeInteger:
                return $this->createSchema(['type' => 'integer', 'exclusiveMaximum' => 0, ...$options]);

            case $type instanceof Reflection\PseudoTypes\NonEmptyList:
                return $this->createSchema(['type' => 'array', 'items' => $this->convertVirtualType($type->getValueType(), $currentClass, [], $definitions), 'minItems' => 1, ...$options]);

            case $type instanceof Reflection\PseudoTypes\NonEmptyLowercaseString:
                return $this->createSchema(['type' => 'string', 'minLength' => 1, 'pattern' => self::LC_STRING_PATTERN, ...$options]);

            case $type instanceof Reflection\PseudoTypes\NonEmptyString:
                return $this->createSchema(['type' => 'string', 'minLength' => 1, ...$options]);

            case $type instanceof Reflection\PseudoTypes\NumericString:
                return $this->createSchema(['type' => 'string', 'pattern' => self::NUMERIC_STRING_PATTERN, ...$options]);

            case $type instanceof Reflection\PseudoTypes\Numeric_:
                return $this->createSchema(['type' => ['number', 'integer', 'string'], 'pattern' => self::NUMERIC_STRING_PATTERN, ...$options]);

            case $type instanceof Reflection\PseudoTypes\PositiveInteger:
                return $this->createSchema(['type' => 'integer', 'exclusiveMinimum' => 0, ...$options]);

            case $type instanceof Reflection\PseudoTypes\StringValue:
                return $this->createSchema(['const' => $type->getValue(), ...$options]);

            case $type instanceof Reflection\PseudoTypes\True_:
                return $this->createSchema(['const' => true, ...$options]);

            case $type instanceof Reflection\Types\Array_:
            case $type instanceof Reflection\Types\Collection:
                return $this->createSchema([
                    'type' => 'object',
                    'additionalProperties' => JsonSchema\Schema::export(
                        $this->convertVirtualType($type->getValueType(), $currentClass, [], $definitions),
                    ),
                    ...$options,
                ]);

            case $type instanceof Reflection\Types\Iterable_:
                throw new LogicException('Iterables cannot be converted to JSON Schema; they are normally serialized to an empty object');

            case $type instanceof Reflection\Types\Compound:
                return ($scalarTypes = $this->tryGettingScalarTypesVirtual($type)) !== null
                    ? $this->createSchema(['type' => $scalarTypes, ...$options])
                    : $this->createSchema([
                        'anyOf' => array_map(
                            fn(Reflection\Type $subType) => JsonSchema\Schema::export(
                                $this->convertVirtualType($subType, $currentClass, [], $definitions),
                            ),
                            iterator_to_array($type->getIterator()),
                        ),
                        ...$options,
                    ]);

            case $type instanceof Reflection\Types\Intersection:
                return $this->createSchema([
                    'allOf' => array_map(
                        fn(Reflection\Type $subType) => JsonSchema\Schema::export(
                            $this->convertVirtualType($subType, $currentClass, [], $definitions),
                        ),
                        iterator_to_array($type->getIterator()),
                    ),
                    ...$options,
                ]);

            case $type instanceof Reflection\Types\Boolean:
                return $this->createSchema(['type' => 'boolean', ...$options]);

            case $type instanceof Reflection\Types\Callable_:
                throw new LogicException('Callable cannot be converted to JSON Schema');

            case $type instanceof Reflection\Types\Expression:
                throw new LogicException('Expression cannot be converted to JSON Schema');

            case $type instanceof Reflection\Types\Float_:
                return $this->createSchema(['type' => 'number', ...$options]);

            case $type instanceof Reflection\Types\Integer:
                return $this->createSchema(['type' => 'integer', ...$options]);

            case $type instanceof Reflection\Types\Mixed_:
                return $this->createSchema($options);

            case $type instanceof Reflection\Types\Never_:
                throw new LogicException('`never` cannot be converted to JSON Schema');

            case $type instanceof Reflection\Types\Nullable:
                $actual = $this->convertVirtualType($type->getActualType(), $currentClass, $options, $definitions);
                $actual->type = ['null', $actual->type];
                return $actual;

            case $type instanceof Reflection\Types\Null_:
                return $this->createSchema(['type' => 'null', ...$options]);

            case $type instanceof Reflection\Types\Object_:
                $targetClass = (string)$type->getFqsen();
                class_exists($targetClass) or throw new RuntimeException("Could not find class `$targetClass`");
                return $this->createSchema(['$ref' => $this->getRegisteredDefinitionRef($targetClass, $definitions), ...$options]);

            case $type instanceof Reflection\Types\Parent_:
                $currentClass or throw new InvalidArgumentException('Cannot convert `parent` type when `$currentClass` is empty');
                $parentClass = get_parent_class($currentClass);
                $parentClass or throw new InvalidArgumentException('Cannot convert `parent` type when `$currentClass` does not extend anything: ' . $currentClass);
                return $this->createSchema(['$ref' => $this->getRegisteredDefinitionRef($parentClass, $definitions), ...$options]);

            case $type instanceof Reflection\Types\Resource_:
                throw new LogicException('Resources cannot be converted to JSON Schema');

            case $type instanceof Reflection\Types\Scalar:
                return $this->createSchema(['type' => ['string', 'integer', 'number', 'boolean'], ...$options]);

            case $type instanceof Reflection\Types\String_:
                return $this->createSchema(['type' => 'string', ...$options]);

            case $type instanceof Reflection\Types\Self_:
            case $type instanceof Reflection\Types\Static_:
            case $type instanceof Reflection\Types\This:
                $currentClass or throw new InvalidArgumentException('Cannot convert `$this` type when `$currentClass` is empty');
                return $this->createSchema(['$ref' => $this->getRegisteredDefinitionRef($currentClass, $definitions), ...$options]);

            case $type instanceof Reflection\Types\Void_:
                throw new LogicException('`void` cannot be converted to JSON Schema');
        }

        // @codeCoverageIgnoreStart
        throw new RuntimeException('Unsupported reflected type: ' . get_class($type));
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param null|class-string $currentClass
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    private function convertNativeType(null|ReflectionType $type, ?string $currentClass, ArrayObject $definitions): JsonSchema\Schema
    {
        switch (true) {
            case $type === null: // missing type is "mixed" type in php by default
                return $this->createSchema([]);

            case $type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'mixed':
                return $this->createSchema([]);

            case $type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'object':
                return $this->createSchema([
                    'type' => $type->allowsNull() ? ['null', 'object'] : 'object',
                    'additionalProperties' => true,
                ]);

            case $type instanceof ReflectionNamedType && $type->isBuiltin() && in_array($type->getName(), ['array', 'iterable']):
                return $this->createSchema([
                    'type' => $type->allowsNull() ? ['null', 'array', 'object'] : ['array', 'object'],
                ]);

            case $type instanceof ReflectionNamedType && $type->isBuiltin():
                $jsType = self::NATIVE_TYPE_MAP[$type->getName()]
                    ?? throw new LogicException("`{$type->getName()}` cannot be converted to JSON Schema");
                return $this->createSchema(['type' => $type->allowsNull() ? ['null', $jsType] : $jsType]);

            case $type instanceof ReflectionNamedType && !$type->isBuiltin() && $type->getName() === 'parent':
                return $this->createSchema([
                    '$ref' => $this->getRegisteredDefinitionRef(
                        get_parent_class(
                            $currentClass ?? throw new LogicException('Current context is not within any class'),
                        ) ?: throw new LogicException("Cannot retrieve parent class of `{$type->getName()}` as it has no parent"),
                        $definitions,
                    ),
                ]);

            case $type instanceof ReflectionNamedType && !$type->isBuiltin():
                return $this->createSchema([
                    /** @phpstan-ignore-next-line */
                    '$ref' => $this->getRegisteredDefinitionRef($type->getName(), $definitions),
                ]);

            case $type instanceof ReflectionUnionType:
                return ($scalarTypes = $this->tryGettingScalarTypesNative($type)) !== null
                    ? $this->createSchema(['type' => $scalarTypes])
                    : $this->createSchema(
                        [
                            'anyOf' => array_merge(
                                array_map(fn($type) => $this->convertNativeType($type, $currentClass, $definitions), $type->getTypes()),
                                $type->allowsNull() ? [$this->createSchema(['type' => 'null'])] : [],
                            ),
                        ],
                    );

            case $type instanceof ReflectionIntersectionType:
                if ($type->allowsNull()) {
                    throw new LogicException('Null cannot be a part of an intersection type (how did you manage to break PHP?)');
                }
                return $this->createSchema([
                    'allOf' => array_map(fn($type) => $this->convertNativeType($type, $currentClass, $definitions), $type->getTypes()),
                ]);

            default:
                throw new RuntimeException('Unsupported type: ' . var_export($type, true));
        }
    }

    /**
     * @return null|list<string>
     */
    private function tryGettingScalarTypesNative(ReflectionUnionType $type): null|array
    {
        $result = [];

        if ($type->allowsNull()) {
            $result[] = 'null';
        }

        foreach ($type->getTypes() as $subType) {
            if (!$subType instanceof ReflectionNamedType) {
                return null;
            }
            if (!$subType->isBuiltin()) {
                return null;
            }
            if ($subType->allowsNull()) {
                $result[] = 'null';
            }
            if (($jsType = self::NATIVE_TYPE_MAP[$subType->getName()] ?? null) === null) {
                return null;
            }
            $result[] = $jsType;
        }

        return array_unique($result);
    }

    /**
     * @return null|list<string>
     */
    private function tryGettingScalarTypesVirtual(Reflection\Types\Compound $type): null|array
    {
        $result = [];

        /** @var Reflection\Type $subType */
        foreach ($type as $subType) {
            if (($jsType = self::VIRTUAL_TYPE_MAP[get_class($subType)] ?? null) === null) {
                return null;
            }
            $result[] = $jsType;
        }

        return array_unique($result);
    }

    private function applyTitleAndDescription(JsonSchema\Schema $schema, string $multilineDescription): void
    {
        $lines = explode("\n", trim($multilineDescription));

        $title = trim($lines[0]);
        if ($title) {
            $schema->title = $title;
        }

        $description = trim(implode("\n", array_slice($lines, 1)));
        if ($description) {
            $schema->description = $description;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @throws Throwable
     */
    private function createSchema(array $options): JsonSchema\Schema
    {
        $schema = new JsonSchema\Schema();
        foreach ($options as $key => $value) {
            $schema->$key = $value;
        }

        return $schema;
    }

    /**
     * @param class-string $class
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    private function getRegisteredDefinitionRef(string $class, ArrayObject $definitions): string
    {
        $reflector = new ReflectionClass($class);
        $classKey = str_replace('\\', '.', $reflector->getName());
        if (!$definitions->offsetExists($classKey)) {
            // to avoid infinite recursion, set reference to empty schema before converting the real one
            $definitions->offsetSet($classKey, new JsonSchema\Schema());
            $definitions->offsetSet($classKey, $this->convertClassLike($reflector, $definitions));
        }

        return "#/definitions/$classKey";
    }

    /**
     * @param ReflectionClass<object> $reflector
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    protected function convertClassLike(ReflectionClass $reflector, ArrayObject $definitions): JsonSchema\Schema
    {
        $docBlock = Reflection\DocBlockFactory::createInstance()->create(trim($reflector->getDocComment() ?: '') ?: "/**\n*/");
        if (!$reflector instanceof ReflectionEnum && $reflector->isEnum()) {
            /**
             * @phpstan-ignore-next-line
             */
            $reflector = new ReflectionEnum($reflector->getName());
        }

        $schema = $reflector instanceof ReflectionEnum
            ? $this->convertEnum($reflector)
            : $this->convertClass($reflector, $docBlock, $definitions);

        $this->applyTitleAndDescription($schema, rtrim("{$docBlock->getSummary()}\n\n{$docBlock->getDescription()}"));

        if ($docBlock->hasTag('deprecated')) {
            $schema->offsetSet('deprecated', true);
        }

        return $schema;
    }

    /**
     * @param ReflectionClass<object> $reflector
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    protected function convertClass(ReflectionClass $reflector, Reflection\DocBlock $docBlock, ArrayObject $definitions): JsonSchema\Schema
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'required' => [],
        ]);

        if ($reflector->isFinal()) {
            $schema->additionalProperties = false;
        }

        $nativeProperties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);
        foreach ($nativeProperties as $property) {
            $schema->setProperty($property->getName(), $this->convertNativeProperty($property, $definitions));
            $schema->required[] = $property->getName();
        }

        /** @var (Reflection\DocBlock\Tags\Property|Reflection\DocBlock\Tags\PropertyRead)[] $virtualProperties */
        $virtualProperties = array_merge(
            $docBlock->getTagsByName('property'),
            $docBlock->getTagsByName('property-read'),
            $docBlock->getTagsByName('property-write'),
        );
        foreach ($virtualProperties as $property) {
            if (!($propertyName = trim($property->getVariableName() ?: ''))) {
                throw new LogicException("Magic property in class `$reflector->name` must have a valid name");
            }
            $schema->setProperty($propertyName, $this->convertVirtualProperty($property, $reflector->name, $definitions));
        }

        $schema->required = array_unique($schema->required);

        return $schema;
    }

    private function convertEnum(ReflectionEnum $reflector): JsonSchema\Schema
    {
        if (!$reflector->isBacked()) {
            throw new LogicException("Cannot convert non-backed enum `$reflector->name` to JSON Schema");
        }

        $backingType = $reflector->getBackingType();
        if (!$backingType instanceof ReflectionNamedType) {
            throw new RuntimeException('Expected backing type to be a `ReflectionNamedType`, got `' . get_debug_type($backingType) . '` instead');
        }

        $convertedBackingType = self::NATIVE_TYPE_MAP[$backingType->getName()]
            ?? throw new RuntimeException("Unsupported backing type `{$backingType->getName()}`");

        return $this->createSchema([
            'type' => $convertedBackingType,
            'enum' => array_map(static fn(ReflectionEnumBackedCase $case) => $case->getBackingValue(), $reflector->getCases()),
        ]);
    }

    /**
     * @param class-string $declaringClass
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    private function convertVirtualProperty(
        Reflection\DocBlock\Tags\Property|Reflection\DocBlock\Tags\PropertyRead $property,
        string                                                                  $declaringClass,
        ArrayObject                                                             $definitions,
    ): JsonSchema\Schema {
        return $this->doConvertTag($property, $declaringClass, $definitions);
    }

    /**
     * @param ArrayObject<string, JsonSchema\Schema> $definitions
     * @throws Throwable
     */
    private function convertNativeProperty(ReflectionProperty $property, ArrayObject $definitions): JsonSchema\Schema
    {
        $docBlock = Reflection\DocBlockFactory::createInstance()->create(trim($property->getDocComment() ?: '') ?: "/**\n*/");

        $varTag = $docBlock->getTagsWithTypeByName('var')[0] ?? null;
        if ($varTag) {
            /**
             * @see https://github.com/phpstan/phpstan/issues/11334
             * @phpstan-ignore-next-line
             */
            $schema = $this->doConvertTag($varTag, $property->class, $definitions);
        } else {
            /**
             * @see https://github.com/phpstan/phpstan/issues/11334
             * @phpstan-ignore-next-line
             */
            $schema = $this->convertNativeType($property->getType(), $property->class, $definitions);
        }

        if ($property->hasDefaultValue()) {
            $schema->default = $property->getDefaultValue();
        }

        if (($summary = trim($docBlock->getSummary())) !== '') {
            $schema->description = $summary;
        }

        if ($docBlock->hasTag('deprecated')) {
            $schema->offsetSet('deprecated', true);
        }

        if ($docBlock->hasTag('readonly') || $property->isReadOnly()) {
            $schema->offsetSet('readOnly', true);
        }

        return $schema;
    }
}
