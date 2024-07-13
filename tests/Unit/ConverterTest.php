<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Unit;

use Exception;
use LogicException;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swaggest\JsonSchema\Schema;
use Throwable;
use uuf6429\PHPDocToJSONSchema\Converter;
use uuf6429\PHPDocToJSONSchemaTests\Fixtures\EmptyClass;

class ConverterTest extends TestCase
{
    /**
     * @param array{phpdocComment: string, expectedResult: array<string, mixed>} $expectedResult
     * @param null|class-string $currentClass
     * @throws Throwable
     */
    #[DataProvider('validConversionsDataProvider')]
    public function testThatValidConversionsWork(string $phpdocComment, array $expectedResult, ?string $currentClass = null): void
    {
        $docblock = DocBlockFactory::createInstance()->create($phpdocComment);
        $converter = new Converter();
        $returnTag = $docblock->getTagsWithTypeByName('return')[0];

        $result = $converter->convertTag($returnTag, $currentClass);

        $this->assertEquals((object)$expectedResult, Schema::export($result));
    }

    /**
     * @return iterable<string, array{phpdocComment: string, expectedResult: array<string, mixed>}>
     */
    public static function validConversionsDataProvider(): iterable
    {
        yield 'simple string' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return string
                 */
                PHP,
            'expectedResult' => [
                'type' => 'string',
            ],
        ];

        yield 'string value' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return 'test'
                 */
                PHP,
            'expectedResult' => [
                'const' => 'test',
            ],
        ];

        yield 'integer' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return int
                 */
                PHP,
            'expectedResult' => [
                'type' => 'integer',
            ],
        ];

        yield 'float' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return float
                 */
                PHP,
            'expectedResult' => [
                'type' => 'number',
            ],
        ];

        yield 'mixed' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return mixed
                 */
                PHP,
            'expectedResult' => [
            ],
        ];

        yield 'integer literal' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return 123
                 */
                PHP,
            'expectedResult' => [
                'const' => 123,
            ],
        ];

        yield 'null literal' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return null
                 */
                PHP,
            'expectedResult' => [
                'type' => 'null',
            ],
        ];

        yield 'nullable string' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return ?string
                 */
                PHP,
            'expectedResult' => [
                'type' => ['null', 'string'],
            ],
        ];

        yield 'scalar' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return scalar
                 */
                PHP,
            'expectedResult' => [
                'type' => ['string', 'integer', 'number', 'boolean'],
            ],
        ];

        yield 'object of integers' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return object<int>
                */
               PHP,
            'expectedResult' => [
                'type' => 'object',
                'additionalProperties' => (object)[
                    'type' => 'integer',
                ],
            ],
        ];

        //        yield 'object shape' => [
        //            'phpdocComment' => <<<'PHP'
        //                /**
        //                 * @return object{aa: string, bb: int}
        //                 */
        //                PHP,
        //            'expectedResult' => [
        //                'type' => ['string', 'integer', 'number', 'boolean'],
        //            ],
        //        ];

        yield 'false or integer' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return false|integer
                */
               PHP,
            'expectedResult' => [
                'anyOf' => [
                    (object)['const' => false],
                    (object)['type' => 'integer'],
                ],
            ],
        ];

        yield 'true or false' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return true|false
                */
               PHP,
            'expectedResult' => [
                'anyOf' => [
                    (object)['const' => true],
                    (object)['const' => false],
                ],
            ],
        ];

        yield 'empty class' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\EmptyClass
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
        ];

        yield 'integer or empty class' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return int|\uuf6429\PHPDocToJSONSchemaTests\Fixtures\EmptyClass
                */
               PHP,
            'expectedResult' => [
                'anyOf' => [
                    (object)['type' => 'integer'],
                    (object)['$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass'],
                ],
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
        ];

        yield 'final empty deprecated class' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\FinalEmptyDeprecatedClass
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.FinalEmptyDeprecatedClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.FinalEmptyDeprecatedClass' => (object)[
                        'type' => 'object',
                        'required' => [],
                        'additionalProperties' => false,
                        'deprecated' => true,
                        'title' => 'Empty Class of Oblivion',
                        'description' => <<<'TEXT'
                            An empty class that:
                            - cannot be extended
                            - will never have any properties
                            TEXT,
                    ],
                ],
            ],
        ];

        yield 'class with native properties' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\ClassWithNativeProperties
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassWithNativeProperties',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassWithNativeProperties' => (object)[
                        'type' => 'object',
                        'required' => [
                            'publicNumber',
                            'publicStrWithDef',
                            'publicStaticBool',
                        ],
                        'properties' => (object)[
                            'publicNumber' => (object)[
                                'type' => ['null', 'integer', 'number'],
                            ],
                            'publicStrWithDef' => (object)[
                                'type' => 'string',
                                'default' => 'something',
                            ],
                            'publicStaticBool' => (object)[
                                'type' => 'boolean',
                                'default' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'class with magic properties' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\ClassWithMagicProperties
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassWithMagicProperties',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassWithMagicProperties' => (object)[
                        'type' => 'object',
                        'required' => [],
                        'properties' => (object)[
                            'descriptiveStr' => (object)[
                                'type' => 'string',
                                'title' => 'A public string property',
                            ],
                            'integerOrBoolean' => (object)[
                                'type' => ['integer', 'boolean'],
                            ],
                            'readonlyAliasType' => (object)[
                                'type' => 'boolean',
                                'readOnly' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'class with augmented properties' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\ClassWithAugmentedProperties
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassWithAugmentedProperties',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassWithAugmentedProperties' => (object)[
                        'type' => 'object',
                        'required' => [
                            'evenNumbers',
                            'promotedProperty',
                        ],
                        'properties' => (object)[
                            'evenNumbers' => (object)[
                                'anyOf' => [
                                    (object)['const' => 2],
                                    (object)['const' => 4],
                                    (object)['const' => 6],
                                    (object)['const' => 8],
                                ],
                                'description' => 'Even numbers between 0 and 10.',
                                'deprecated' => true,
                            ],
                            'promotedProperty' => (object)[
                                'type' => 'string',
                                'description' => 'A promoted property',
                                'readOnly' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'int-backed enum' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\IntEnum
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.IntEnum',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.IntEnum' => (object)[
                        'type' => 'integer',
                        'enum' => [1, 2],
                    ],
                ],
            ],
        ];

        yield 'string-backed enum' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\StringEnum
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.StringEnum',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.StringEnum' => (object)[
                        'type' => 'string',
                        'enum' => ['a', 'b'],
                    ],
                ],
            ],
        ];

        yield 'class referencing another class' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\ClassReferencingEmptyClass
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassReferencingEmptyClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.ClassReferencingEmptyClass' => (object)[
                        'type' => 'object',
                        'required' => ['empty'],
                        'properties' => (object)[
                            'empty' => (object)[
                                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
        ];

        yield 'class referencing itself' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\SelfReferencingClass
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.SelfReferencingClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.SelfReferencingClass' => (object)[
                        'type' => 'object',
                        'required' => [
                            'name',
                            'parent',
                        ],
                        'additionalProperties' => false,
                        'properties' => (object)[
                            'name' => (object)[
                                'type' => 'string',
                            ],
                            'parent' => (object)[
                                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.SelfReferencingClass',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'referencing empty class as self' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return self
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
            'currentClass' => EmptyClass::class,
        ];

        yield 'referencing empty class as static' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return static
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
            'currentClass' => EmptyClass::class,
        ];

        yield 'referencing empty class as $this' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return $this
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
            'currentClass' => EmptyClass::class,
        ];

        yield 'class referencing parent' => [
            'phpdocComment' => <<<'PHP'
               /**
                * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\LessEmptyClass
                */
               PHP,
            'expectedResult' => [
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.LessEmptyClass',
                'definitions' => (object)[
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.LessEmptyClass' => (object)[
                        'type' => 'object',
                        'required' => [
                            'parent',
                            'maybeParent',
                        ],
                        'properties' => (object)[
                            'parent' => (object)[
                                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                            ],
                            'maybeParent' => (object)[
                                'default' => null,
                                'anyOf' => [
                                    (object)[
                                        'type' => 'null',
                                    ],
                                    (object)[
                                        '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass',
                                    ],
                                    (object)[
                                        '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.LessEmptyClass',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.EmptyClass' => (object)[
                        'type' => 'object',
                        'title' => 'Liberal Empty Class',
                        'required' => [],
                    ],
                ],
            ],
            'currentClass' => EmptyClass::class,
        ];
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidConversionsDataProvider')]
    public function testThatInvalidConversionsFail(string $phpdocComment, Exception $expectedException): void
    {
        $docblock = DocBlockFactory::createInstance()->create($phpdocComment);
        $converter = new Converter();
        $returnTag = $docblock->getTagsWithTypeByName('return')[0];

        $this->expectException(get_class($expectedException));
        $this->expectExceptionMessage($expectedException->getMessage());

        $converter->convertTag($returnTag, null);
    }

    /**
     * @return iterable<string, array{phpdocComment: string, expectedException: Exception}>
     */
    public static function invalidConversionsDataProvider(): iterable
    {
        yield 'self cannot be resolved without a current class' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return self
                 */
                PHP,
            'expectedException' => new LogicException('Cannot convert `$this` type when `$currentClass` is empty'),
        ];

        yield '"void" cannot be converted' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return void
                 */
                PHP,
            'expectedException' => new LogicException('`void` cannot be converted to JSON Schema'),
        ];

        yield '"never" cannot be converted' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return never
                 */
                PHP,
            'expectedException' => new LogicException('`never` cannot be converted to JSON Schema'),
        ];

        yield 'resources cannot be converted' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return resource
                 */
                PHP,
            'expectedException' => new LogicException('Resources cannot be converted to JSON Schema'),
        ];

        yield 'callable cannot be converted' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return callable
                 */
                PHP,
            'expectedException' => new LogicException('Callable cannot be converted to JSON Schema'),
        ];

        yield 'non-backed enum cannot be converted' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return \uuf6429\PHPDocToJSONSchemaTests\Fixtures\NonBackedEnum
                 */
                PHP,
            'expectedException' => new LogicException('Cannot convert non-backed enum `uuf6429\PHPDocToJSONSchemaTests\Fixtures\NonBackedEnum` to JSON Schema'),
        ];

        yield 'missing type cannot be converted' => [
            'phpdocComment' => <<<'PHP'
                /**
                 * @return missing
                 */
                PHP,
            'expectedException' => new RuntimeException('Could not find class `\missing`'),
        ];
    }
}
