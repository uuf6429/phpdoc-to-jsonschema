<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures;

final class SelfReferencingClass
{
    public string $name;
    public SelfReferencingClass $parent;
}
