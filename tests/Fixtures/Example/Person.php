<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures\Example;

class Person
{
    public function __construct(
        public readonly string    $name,
        public readonly int|float $height,
    ) {
        //
    }
}
