<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Unit;

use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Swaggest\JsonSchema\Schema;
use uuf6429\PHPDocToJSONSchema\Converter;
use uuf6429\PHPDocToJSONSchemaTests\Fixtures\Example\GetPersonEndpoint;
use uuf6429\PHPStanPHPDocTypeResolver\PhpDoc;

class ExampleTest extends TestCase
{
    public function testThatExampleWorks(): void
    {
        $converter = new Converter();
        $endpointMethod = new ReflectionMethod(GetPersonEndpoint::class, '__invoke');
        $docblock = PhpDoc\Factory::createInstance()->createFromReflector($endpointMethod);
        /** @var ReturnTagValueNode $endpointReturnDocblockTag */
        $endpointReturnDocblockTag = $docblock->getTag('@return');

        $result = $converter->convertType($endpointReturnDocblockTag->type, GetPersonEndpoint::class);

        $this->assertEquals(
            (object)[
                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.Example.Response<uuf6429.PHPDocToJSONSchemaTests.Fixtures.Example.Person>',
                'definitions' => (object)[
                    '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.Example.Response<uuf6429.PHPDocToJSONSchemaTests.Fixtures.Example.Person>' => (object)[
                        'type' => 'object',
                        'properties' => (object)[
                            'data' => (object)[
                                '$ref' => '#/definitions/uuf6429.PHPDocToJSONSchemaTests.Fixtures.Example.Person',
                            ],
                        ],
                        'required' => ['data'],
                    ],
                    'uuf6429.PHPDocToJSONSchemaTests.Fixtures.Example.Person' => (object)[
                        'type' => 'object',
                        'properties' => (object)[
                            'name' => (object)[
                                'type' => 'string',
                                'readOnly' => true,
                            ],
                            'height' => (object)[
                                'type' => ['integer', 'number'],
                                'readOnly' => true,
                            ],
                        ],
                        'required' => ['name', 'height'],
                    ],
                ],
            ],
            Schema::export($result),
        );
    }
}
