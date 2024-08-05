<?php

namespace uuf6429\PHPDocToJSONSchema;

use InvalidArgumentException;
use LogicException;
use PHPStan\PhpDocParser;
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
use uuf6429\PHPStanPHPDocTypeResolver\PhpDoc\Block;
use uuf6429\PHPStanPHPDocTypeResolver\PhpDoc\Factory;
use uuf6429\PHPStanPHPDocTypeResolver\PhpDoc\Types\ConcreteGenericTypeNode;
use uuf6429\PHPStanPHPDocTypeResolver\PhpDoc\Types\TemplateTypeNode;

class Converter
{
    /**
     * Note: this naive approach (instead of e.g. unicode category) is intentional - it matches the original
     * implementation in Psalm.
     * @see https://github.com/vimeo/psalm/issues/11022
     */
    private const LOWERCASE_STRING_PATTERN = '/^[^A-Z]*$/';

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

    private const INVALID_TYPES = [
        'callable',
        'resource',
        'void',
        'never',
    ];

    /**
     * @param class-string|null $currentClass Fully-qualified class name of where the type occurred, when applicable.
     * @param array<string, string> $genericTypesMap A mapping of <template type> => <concrete type> pairs, for use when `$type` is or contains generic templates.
     * @throws Throwable
     */
    public function convertType(PhpDocParser\Ast\Type\TypeNode $type, ?string $currentClass, array $genericTypesMap = []): JsonSchema\Schema
    {
        $definitions = new Definitions();

        $result = $this->convertVirtualType($type, $currentClass, [], $definitions, $genericTypesMap);

        if (!$definitions->isComplete()) {
            throw new RuntimeException('Definitions have not been built successfully');
        }

        if (!$definitions->isEmpty()) {
            $result->definitions = $definitions->mergeWith($result->definitions);
        }

        return $result;
    }

    /**
     * @param null|class-string $currentClass
     * @param array<string, mixed> $options
     * @param array<string, string> $genericTypesMap
     * @throws Throwable
     */
    private function convertVirtualType(PhpDocParser\Ast\Type\TypeNode $type, ?string $currentClass, array $options, Definitions $definitions, array $genericTypesMap): JsonSchema\Schema
    {
        $constExpr = $type instanceof PhpDocParser\Ast\Type\ConstTypeNode ? $type->constExpr : null;

        return match (true) {
            $type instanceof TemplateTypeNode
            => $this->convertVirtualType(
                type: new PhpDocParser\Ast\Type\IdentifierTypeNode(
                    $genericTypesMap[$type->name]
                    ?? $type->bound?->name
                    ?? throw new RuntimeException('Template Type node should point to a valid template type (defined with an "@template" tag) or at least a valid lower bound (e.g. "T of bound")'),
                ),
                currentClass: $currentClass,
                options: [],
                definitions: $definitions,
                genericTypesMap: $genericTypesMap,
            ),

            $type instanceof PhpDocParser\Ast\Type\InvalidTypeNode
            => throw new LogicException('Invalid node cannot be converted to JSON Schema', 0, $type->getException()),

            $type instanceof PhpDocParser\Ast\Type\ObjectShapeNode
            => $this->createSchema([
                'type' => 'object',
                'properties' => (object)array_combine(
                    array_map(strval(...), array_column($type->items, 'keyName')),
                    array_map(
                        fn(PhpDocParser\Ast\Type\ObjectShapeItemNode $item) => JsonSchema\Schema::export(
                            $this->convertVirtualType($item->valueType, $currentClass, [], $definitions, $genericTypesMap),
                        ),
                        $type->items,
                    ),
                ),
                'required' => array_values(
                    array_filter(
                        array_map(
                            static fn(PhpDocParser\Ast\Type\ObjectShapeItemNode $item): ?string => $item->optional ? null : (string)$item->keyName,
                            $type->items,
                        ),
                    ),
                ),
                'additionalProperties' => true,
            ]),

            $type instanceof PhpDocParser\Ast\Type\ConditionalTypeForParameterNode
            => throw new RuntimeException('TODO 1'), // TODO

            $type instanceof PhpDocParser\Ast\Type\ConditionalTypeNode
            => throw new RuntimeException('TODO 2'), // TODO

            $type instanceof PhpDocParser\Ast\Type\ArrayShapeItemNode
            => throw new RuntimeException('TODO 3'), // TODO

            $type instanceof PhpDocParser\Ast\Type\ArrayShapeNode
            => throw new RuntimeException('TODO 4'), // TODO

            $type instanceof PhpDocParser\Ast\Type\ArrayTypeNode
            => throw new RuntimeException('TODO 5'), // TODO

            $type instanceof PhpDocParser\Ast\Type\GenericTypeNode
            => $this->createSchema([
                '$ref' => $this->getRegisteredGenericDefinitionRef($type, $definitions),
                ...$options,
            ]),

            $type instanceof PhpDocParser\Ast\Type\IntersectionTypeNode
            => $this->createSchema([
                'allOf' => array_map(
                    fn(PhpDocParser\Ast\Type\TypeNode $subType) => JsonSchema\Schema::export(
                        $this->convertVirtualType($subType, $currentClass, [], $definitions, $genericTypesMap),
                    ),
                    $type->types,
                ),
                ...$options,
            ]),

            $type instanceof PhpDocParser\Ast\Type\OffsetAccessTypeNode
            => throw new RuntimeException('TODO 7'), // TODO

            $type instanceof PhpDocParser\Ast\Type\IdentifierTypeNode
            => match (true) {
                in_array($type->name, self::INVALID_TYPES)
                => throw new LogicException("`$type->name` cannot be converted to JSON Schema"),

                ($jsType = self::NATIVE_TYPE_MAP[$type->name] ?? null) !== null
                => $this->createSchema(['type' => $jsType, ...$options]),

                $type->name === 'mixed'
                => $this->createSchema($options),

                $type->name === 'object'
                => $this->createSchema(['type' => 'object', 'additionalProperties' => true, ...$options]),

                $type->name === 'true'
                => $this->createSchema(['const' => true, ...$options]),

                $type->name === 'false'
                => $this->createSchema(['const' => false, ...$options]),

                $type->name === 'scalar'
                => $this->createSchema(['type' => ['string', 'integer', 'number', 'boolean'], ...$options]),

                $type->name === 'lowercase-string'
                => $this->createSchema(['type' => 'string', 'pattern' => self::LOWERCASE_STRING_PATTERN, ...$options]),

                $type->name === 'numeric-string'
                => $this->createSchema(['type' => 'string', 'pattern' => self::NUMERIC_STRING_PATTERN, ...$options]),

                $type->name === 'numeric'
                => $this->createSchema([
                    'anyOf' => [
                        (object)['type' => 'number'],
                        (object)['type' => 'integer'],
                        (object)['type' => 'string', 'pattern' => self::NUMERIC_STRING_PATTERN],
                    ],
                    ...$options,
                ]),

                interface_exists($type->name),
                trait_exists($type->name),
                enum_exists($type->name),
                class_exists($type->name)
                => $this->createSchema([
                    '$ref' => $this->getRegisteredDefinitionRef($type->name, $definitions),
                    ...$options,
                ]),

                default
                => throw new RuntimeException("`$type->name` (" . get_debug_type($type) . ") cannot be converted to JSON Schema"),
            },

            $type instanceof PhpDocParser\Ast\Type\NullableTypeNode
            => $this->makeSchemaNullable($this->convertVirtualType($type->type, $currentClass, $options, $definitions, $genericTypesMap)),

            $type instanceof PhpDocParser\Ast\Type\ConstTypeNode
            => match (true) {
                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprArrayNode
                => throw new RuntimeException('TODO 8.0'), // TODO

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode
                => $this->createSchema(['const' => (int)$constExpr->value, ...$options]),

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprFloatNode
                => $this->createSchema(['const' => (float)$constExpr->value, ...$options]),

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprNullNode
                => $this->createSchema(['const' => null, ...$options]),

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprArrayItemNode
                => throw new RuntimeException('TODO 8.1'), // TODO

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\QuoteAwareConstExprStringNode
                => throw new RuntimeException('TODO 8.2'), // TODO

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprStringNode
                => $this->createSchema(['const' => $constExpr->value, ...$options]),

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\DoctrineConstExprStringNode
                => throw new RuntimeException('TODO 8.4'), // TODO

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstFetchNode
                => throw new RuntimeException('TODO 8.5'), // TODO

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprFalseNode
                => $this->createSchema(['const' => false, ...$options]),

                $constExpr instanceof PhpDocParser\Ast\ConstExpr\ConstExprTrueNode
                => $this->createSchema(['const' => true, ...$options]),

                default
                => throw new RuntimeException('Constant expression is not supported: ' . get_debug_type($constExpr)),
            },

            $type instanceof PhpDocParser\Ast\Type\CallableTypeNode
            => throw new LogicException('`callable` cannot be converted to JSON Schema'),

            $type instanceof PhpDocParser\Ast\Type\ThisTypeNode
            => $this->createSchema([
                '$ref' => $this->getRegisteredDefinitionRef(
                    $currentClass ?? throw new InvalidArgumentException('Cannot convert `$this` type when `$currentClass` is empty'),
                    $definitions,
                ),
                ...$options,
            ]),

            $type instanceof PhpDocParser\Ast\Type\ObjectShapeItemNode
            => throw new RuntimeException('TODO 10'), // TODO

            $type instanceof PhpDocParser\Ast\Type\UnionTypeNode
            => ($scalarTypes = $this->tryGettingScalarTypesVirtual($type)) !== null
                ? $this->createSchema(['type' => $scalarTypes, ...$options])
                : $this->createSchema([
                    'anyOf' => array_map(
                        fn(PhpDocParser\Ast\Type\TypeNode $subType) => JsonSchema\Schema::export(
                            $this->convertVirtualType($subType, $currentClass, [], $definitions, $genericTypesMap),
                        ),
                        $type->types,
                    ),
                    ...$options,
                ]),

            default
            => throw new RuntimeException('Unsupported type `' . get_debug_type($type) . '` cannot be converted to JSON Schema'),
        };
    }

    /**
     * @param null|class-string $currentClass
     * @throws Throwable
     */
    private function convertNativeType(null|ReflectionType $type, ?string $currentClass, Definitions $definitions): JsonSchema\Schema
    {
        switch (true) {
            case $type === null:
                // "missing type" is "mixed" type in php by default
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
    private function tryGettingScalarTypesVirtual(PhpDocParser\Ast\Type\UnionTypeNode $type): null|array
    {
        $result = [];

        foreach ($type->types as $subType) {
            if (!$subType instanceof PhpDocParser\Ast\Type\IdentifierTypeNode) {
                return null;
            }
            if (($jsType = self::NATIVE_TYPE_MAP[$subType->name] ?? null) === null) {
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
     * @throws Throwable
     */
    private function getRegisteredDefinitionRef(string $class, Definitions $definitions): string
    {
        $classKey = str_replace('\\', '.', ltrim($class, '\\'));
        $definitions->defineIfNotDefined(
            $classKey,
            fn() => $this->convertClassLike(
                new ReflectionClass(
                    class_exists($class)
                        ? $class
                        : throw new LogicException("`$classKey` cannot be converted to JSON Schema"),
                ),
                [],
                $definitions,
            ),
        );

        return "#/definitions/$classKey";
    }

    /**
     * @throws Throwable
     */
    private function getRegisteredGenericDefinitionRef(PhpDocParser\Ast\Type\GenericTypeNode $type, Definitions $definitions): string
    {
        if (!$type instanceof ConcreteGenericTypeNode) {
            throw new RuntimeException("Incomplete/unresolved generic type cannot be converted to JSON Schema: $type");
        }

        $classKey = str_replace('\\', '.', $this->generateGenericClassName($type));
        $definitions->defineIfNotDefined(
            $classKey,
            fn() => $this->convertClassLike(
                new ReflectionClass(
                    class_exists($class = $type->type->name)
                        ? $class
                        : throw new LogicException("`$classKey` cannot be converted to JSON Schema"),
                ),
                array_combine(
                    array_map(
                        static fn(?object $node) => match (true) {
                            $node instanceof TemplateTypeNode => $node->name,
                            $node instanceof PhpDocParser\Ast\Type\IdentifierTypeNode => $node->name,
                            default => throw new RuntimeException("Template Type `" . get_debug_type($node) . "` is not supported: $node"),
                        },
                        $type->templateTypes,
                    ),
                    array_map(
                        static fn(?object $node) => match (true) {
                            $node instanceof TemplateTypeNode => $node->name,
                            $node instanceof PhpDocParser\Ast\Type\IdentifierTypeNode => $node->name,
                            default => throw new RuntimeException("Concrete Type `" . get_debug_type($node) . "` is not supported: $node"),
                        },
                        $type->genericTypes,
                    ),
                ),
                $definitions,
            ),
        );

        return "#/definitions/$classKey";
    }

    private function generateGenericClassName(PhpDocParser\Ast\Type\GenericTypeNode $type): string
    {
        return sprintf(
            '%s<%s>',
            ltrim($type->type->name, '\\'),
            implode(
                ',',
                array_map(
                    static fn(PhpDocParser\Ast\Type\TypeNode $subType) => $subType instanceof PhpDocParser\Ast\Type\IdentifierTypeNode
                        ? ltrim($subType->name, '\\')
                        : throw new RuntimeException("Generic subtype must by an identifier, got `" . get_debug_type($subType) . "` instead"),
                    $type->genericTypes,
                ),
            ),
        );
    }

    /**
     * @param ReflectionClass<object> $reflector
     * @param array<string, string> $genericTypesMap
     * @throws Throwable
     */
    protected function convertClassLike(ReflectionClass $reflector, array $genericTypesMap, Definitions $definitions): JsonSchema\Schema
    {
        $docBlock = Factory::createInstance()->createFromReflector($reflector);
        if (!$reflector instanceof ReflectionEnum && $reflector->isEnum()) {
            $reflector = new ReflectionEnum($reflector->getName());
        }

        $schema = $reflector instanceof ReflectionEnum
            ? $this->convertEnum($reflector)
            : $this->convertClass($reflector, $docBlock, $genericTypesMap, $definitions);

        $this->applyTitleAndDescription($schema, rtrim("{$docBlock->getSummary()}\n\n{$docBlock->getDescription()}"));

        if ($docBlock->hasTag('@deprecated')) {
            $schema->offsetSet('deprecated', true);
        }

        return $schema;
    }

    /**
     * @param ReflectionClass<object> $reflector
     * @param array<string, string> $genericTypesMap
     * @throws Throwable
     */
    protected function convertClass(ReflectionClass $reflector, Block $docBlock, array $genericTypesMap, Definitions $definitions): JsonSchema\Schema
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
            $schema->setProperty($property->getName(), $this->convertNativeProperty($property, $genericTypesMap, $definitions));
            $schema->required[] = $property->getName();
        }

        /** @var list<array{props: list<PhpDocParser\Ast\PhpDoc\PropertyTagValueNode>, attrs: array<string, mixed>}> $virtualProperties */
        $virtualProperties = [
            [
                'props' => $docBlock->getTags('@property'),
                'attrs' => [],
            ],
            [
                'props' => $docBlock->getTags('@property-read'),
                'attrs' => ['readOnly' => true],
            ],
            [
                'props' => $docBlock->getTags('@property-write'),
                'attrs' => ['writeOnly' => true],
            ],
        ];
        foreach ($virtualProperties as $propertySet) {
            foreach ($propertySet['props'] as $property) {
                $propertySchema = $this->convertVirtualType($property->type, $reflector->name, $propertySet['attrs'], $definitions, $genericTypesMap);
                foreach ($propertySet['attrs'] as $key => $val) {
                    $propertySchema->offsetSet($key, $val);
                }
                $this->applyTitleAndDescription($propertySchema, $property->description);
                $schema->setProperty(substr($property->propertyName, 1), $propertySchema);
            }
        }

        $schema->required = array_unique($schema->required ?: []);

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
     * @param array<string, string> $genericTypesMap
     * @throws Throwable
     */
    private function convertNativeProperty(ReflectionProperty $property, array $genericTypesMap, Definitions $definitions): JsonSchema\Schema
    {
        $docBlock = Factory::createInstance()->createFromReflector($property);

        /** @var null|PhpDocParser\Ast\PhpDoc\VarTagValueNode $varTag */
        $varTag = $docBlock->findTag('@var');
        $schema = $varTag
            ? $this->convertVirtualType($varTag->type, $property->class, [], $definitions, $genericTypesMap)
            : $this->convertNativeType($property->getType(), $property->class, $definitions);

        if ($property->hasDefaultValue()) {
            $schema->default = $property->getDefaultValue();
        }

        if (($summary = trim($docBlock->getSummary())) !== '') {
            $schema->description = $summary;
        }

        if ($docBlock->hasTag('@deprecated')) {
            $schema->offsetSet('deprecated', true);
        }

        if ($docBlock->hasTag('@readonly') || $property->isReadOnly()) {
            $schema->offsetSet('readOnly', true);
        }

        return $schema;
    }

    private function makeSchemaNullable(JsonSchema\Schema $schema): JsonSchema\Schema
    {
        $schema->type = ['null', ...(is_string($schema->type) ? [$schema->type] : $schema->type)];

        return $schema;
    }
}
