<?php

namespace uuf6429\PHPDocToJSONSchema;

use Swaggest\JsonSchema\JsonSchema;
use Throwable;

class Definitions
{
    private const INCOMPLETE = 'incomplete';

    /**
     * @var array<string, self::INCOMPLETE|JsonSchema>
     */
    private array $definitions = [];

    public function isComplete(): bool
    {
        return !in_array(self::INCOMPLETE, $this->definitions, true);
    }

    public function isEmpty(): bool
    {
        return empty($this->definitions);
    }

    /**
     * @param callable(): JsonSchema $builder
     * @throws Throwable
     */
    public function defineIfNotDefined(string $key, callable $builder): void
    {
        if ($this->defined($key)) {
            return;
        }

        $this->define($key, $builder);
    }

    /**
     * @param callable(): JsonSchema $builder
     * @throws Throwable
     */
    public function define(string $key, callable $builder): void
    {
        try {
            $this->definitions[$key] = self::INCOMPLETE;
            $definition = $builder();
            $this->definitions[$key] = $definition;
        } catch (Throwable $ex) {
            unset($this->definitions[$key]);
            throw $ex;
        }
    }

    /**
     * @param object|array<JsonSchema>|null $definitions
     */
    public function mergeWith(object|array|null $definitions): object
    {
        return (object)array_merge((array)($definitions ?? []), $this->definitions);
    }

    public function defined(string $key): bool
    {
        return array_key_exists($key, $this->definitions);
    }
}
