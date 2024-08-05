# 📩 PHPDoc to JSON Schema

[![CI](https://github.com/uuf6429/phpdoc-to-jsonschema/actions/workflows/ci.yml/badge.svg)](https://github.com/uuf6429/phpdoc-to-jsonschema/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/uuf6429/phpdoc-to-jsonschema/branch/main/graph/badge.svg)](https://codecov.io/gh/uuf6429/phpdoc-to-jsonschema)
[![Minimum PHP Version](https://img.shields.io/badge/php-%5E8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-428F7E.svg)](https://github.com/uuf6429/phpdoc-to-jsonschema/blob/main/LICENSE)
[![Latest Stable Version](https://poser.pugx.org/uuf6429/phpdoc-to-jsonschema/v)](https://packagist.org/packages/uuf6429/phpdoc-to-jsonschema)
[![Latest Unstable Version](https://poser.pugx.org/uuf6429/phpdoc-to-jsonschema/v/unstable)](https://packagist.org/packages/uuf6429/phpdoc-to-jsonschema)

Convert [PHPStan](https://phpstan.org/)-style PHPDoc to JSON Schema.

## 💾 Installation

This package can be installed with [Composer](https://getcomposer.org), simply run the following:

```shell
composer require uuf6429/phpdoc-to-jsonschema
```

_Consider using `--dev` if you intend to use this library during development only._

## 🚀 Usage

The following code:

```php
<?php

namespace MyApp;

// Define an example class to be featured in the json schema
class Person
{
    public function __construct(
        public readonly string $name,
        public readonly int    $height,
    ) {
    }
}

// Load a PHPDoc block that should return an instance of the Person class
$docblock = \uuf6429\PHPStanPHPDocTypeResolver\PhpDoc\Factory::createInstance()
    ->createFromComment('/** @return Person */');

// Retrieve the @return tag for that docblock.
/** @var \PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode $returnTag */
$returnTag = $docblock->getTag('@return');

// Convert that @return tag to JSON Schema
// (note that convertTag() takes typed tags, for example: @param, @var, @property[-read/-write] and of course @return)
$converter = new \uuf6429\PHPDocToJSONSchema\Converter();
$result = $converter->convertType($returnTag->type, null);

// Export the schema and print it out as json
echo json_encode(\Swaggest\JsonSchema\Schema::export($result), JSON_PRETTY_PRINT);
```
...results in something like:
```json
{
    "$ref": "#\/definitions\/MyApp.Person",
    "definitions": {
        "MyApp.Person": {
            "type": "object",
            "properties": {
                "name": {
                    "type": "string",
                    "readOnly": true
                },
                "height": {
                    "type": "integer",
                    "readOnly": true
                }
            },
            "required": [
                "name",
                "height"
            ]
        }
    }
}
```

See also [`ExampleTest`](https://github.com/uuf6429/phpdoc-to-jsonschema/blob/main/tests/Unit/ExampleTest.php) for a more complex example.

## 📖 Documentation

The `\uuf6429\PHPDocToJSONSchema\Converter` class exposes the following:

- ```php
  function convertType(\phpDocumentor\Reflection\Type $type, ?string $currentClass): \Swaggest\JsonSchema\Schema
  ```
  Converts the provided PHPDoc type and returns its schema.
    - `$type` The PHPDoc type to be converted.
    - `$currentClass` The fully-qualified class name of the class where that type appeared, or null if wasn't a class (e.g. for functions).
