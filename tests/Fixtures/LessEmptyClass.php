<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures;

class LessEmptyClass extends EmptyClass
{
    public parent $parent;

    /**
     * @var null|parent|self
     */
    public $maybeParent;
}
