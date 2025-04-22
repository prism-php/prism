<?php

namespace Prism\Prism\Concerns;

use Illuminate\Support\Str;

trait Withable
{
    /**
     * @param  list<mixed>  $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        if (method_exists($this, $name)) {
            return $this->{$name}(...$arguments);
        }

        $propertyName = Str::of($name)->after('with')->camel()->value();

        if (property_exists($this, $propertyName)) {
            return new self(
                ...array_merge(
                    get_object_vars($this),
                    [$propertyName => array_values($arguments)[0]]
                )
            );
        }

        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}
