<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures\Example;

/**
 * @template T of object
 */
class Response
{
    /**
     * @param T $data
     */
    public function __construct(
        /**
         * @var T
         */
        public readonly object $data,
    ) {
        //
    }
}
