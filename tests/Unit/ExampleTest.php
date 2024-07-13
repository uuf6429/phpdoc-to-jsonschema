<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Unit;

use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Context;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Swaggest\JsonSchema\Schema;
use uuf6429\PHPDocToJSONSchema\Converter;
use uuf6429\PHPDocToJSONSchemaTests\Fixtures\Example\GetPersonEndpoint;

class ExampleTest extends TestCase
{
    public function testThatExampleWorks(): void
    {
        $endpointMethod = new ReflectionMethod(GetPersonEndpoint::class, '__invoke');
        $endpointDocblock = DocBlockFactory::createInstance()
            ->create($endpointMethod, new Context($endpointMethod->getDeclaringClass()->getNamespaceName()));
        $endpointReturnDocblockTag = $endpointDocblock->getTagsWithTypeByName('return')[0];

        $converter = new Converter();
        $result = $converter->convertTag($endpointReturnDocblockTag, GetPersonEndpoint::class);

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
