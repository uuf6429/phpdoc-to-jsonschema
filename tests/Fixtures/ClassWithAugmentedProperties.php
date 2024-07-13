<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures;

class ClassWithAugmentedProperties
{
    /**
     * Even numbers between 0 and 10.
     * @var 2|4|6|8
     * @deprecated
     */
    public int $evenNumbers;

    public function __construct(
        /**
         * A promoted property
         */
        public readonly string $promotedProperty,
    ) {
        //
    }
}
