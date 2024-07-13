<?php

namespace uuf6429\PHPDocToJSONSchemaTests\Fixtures;

class ClassWithNativeProperties
{
    public null|int|float $publicNumber;
    public string $publicStrWithDef = 'something';
    protected int $protectedInt;
    public static bool $publicStaticBool = true;
}
