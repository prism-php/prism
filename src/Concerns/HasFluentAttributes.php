<?php

namespace Prism\Prism\Concerns;

use BadMethodCallException;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait HasFluentAttributes
{
    /**
     * @param  list<mixed>  $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        $propertyName = Str::of($name)->after('with')->camel()->value();

        if (! property_exists($this, $propertyName)) {
            throw new BadMethodCallException("Method {$name} does not exist.");
        }

        if (count($arguments) !== 1) {
            throw new InvalidArgumentException("Method {$name} expects exactly one argument.");
        }

        return new self(
            ...array_merge(
                get_object_vars($this),
                [$propertyName => array_values($arguments)[0]]
            )
        );
    }
}
