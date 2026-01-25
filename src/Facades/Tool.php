<?php

declare(strict_types=1);

namespace Prism\Prism\Facades;

use BadMethodCallException;
use Closure;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Tool as BaseTool;

/**
 * @method static BaseTool as(string $name)
 * @method static BaseTool for(string $description)
 * @method static BaseTool using(Closure|callable $fn)
 * @method static BaseTool make(string|Tool|\Laravel\Mcp\Server\Tool $tool)
 * @method static BaseTool concurrent(bool $concurrent = true)
 * @method static BaseTool withParameter(Schema $parameter, bool $required = true)
 * @method static BaseTool withStringParameter(string $name, string $description, bool $required = true)
 * @method static BaseTool withNumberParameter(string $name, string $description, bool $required = true)
 * @method static BaseTool withBooleanParameter(string $name, string $description, bool $required = true)
 * @method static BaseTool withEnumParameter(string $name, string $description, array<string> $options, bool $required = true)
 * @method static BaseTool withArrayParameter(string $name, string $description, Schema $items, bool $required = true)
 * @method static BaseTool withObjectParameter(string $name, string $description, array<string, Schema> $properties, array<string> $requiredFields = [], bool $allowAdditionalProperties = false, bool $required = true)
 */
class Tool
{
    /** @param array<int, mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): BaseTool
    {
        $instance = new BaseTool;

        if (method_exists($instance, $method)) {
            return $instance->$method(...$arguments);
        }

        throw new BadMethodCallException("Method {$method} does not exist.");
    }
}
