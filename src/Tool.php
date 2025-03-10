<?php

declare(strict_types=1);

namespace Prism\Prism;

use ArgumentCountError;
use Closure;
use Error;
use InvalidArgumentException;
use Prism\Prism\Concerns\HasProviderMeta;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;
use TypeError;

class Tool
{
    use HasProviderMeta;

    protected string $name = '';

    protected string $description;

    /** @var array<string, array<string, mixed>> */
    protected array $parameters = [];

    /** @var array <int, string> */
    protected array $requiredParameters = [];

    /** @var Closure():string|callable():string */
    protected $fn;

    public function as(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function for(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function using(Closure|callable $fn): self
    {
        $this->fn = $fn;

        return $this;
    }

    public function withParameter(Schema $parameter, bool $required = true): self
    {
        $this->parameters[$parameter->name()] = $parameter->toArray();

        if ($required) {
            $this->requiredParameters[] = $parameter->name();
        }

        return $this;
    }

    public function withStringParameter(string $name, string $description, bool $required = true): self
    {
        $this->withParameter(new StringSchema($name, $description), $required);

        return $this;
    }

    public function withNumberParameter(string $name, string $description, bool $required = true): self
    {
        $this->withParameter(new NumberSchema($name, $description), $required);

        return $this;
    }

    public function withBooleanParameter(string $name, string $description, bool $required = true): self
    {
        $this->withParameter(new BooleanSchema($name, $description), $required);

        return $this;
    }

    public function withArrayParameter(
        string $name,
        string $description,
        Schema $items,
        bool $required = true,
    ): self {
        $this->withParameter(new ArraySchema($name, $description, $items), $required);

        return $this;
    }

    /**
     * @param  array<int, Schema>  $properties
     * @param  array<int, string>  $requiredFields
     */
    public function withObjectParameter(
        string $name,
        string $description,
        array $properties,
        array $requiredFields = [],
        bool $allowAdditionalProperties = false,
        bool $required = true,
    ): self {

        $this->withParameter(new ObjectSchema(
            $name,
            $description,
            $properties,
            $requiredFields,
            $allowAdditionalProperties,
        ), $required);

        return $this;
    }

    /**
     * @param  array<int, string|int|float>  $options
     */
    public function withEnumParameter(
        string $name,
        string $description,
        array $options,
        bool $required = true,
    ): self {
        $this->withParameter(new EnumSchema($name, $description, $options), $required);

        return $this;
    }

    /** @return array<int, string> */
    public function requiredParameters(): array
    {
        return $this->requiredParameters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function hasParameters(): bool
    {
        return (bool) count($this->parameters);
    }

    /**
     * @param  string|int|float  $args
     *
     * @throws PrismException|Throwable
     */
    public function handle(...$args): string
    {
        try {
            $value = call_user_func($this->fn, ...$args);

            if (! is_string($value)) {
                throw PrismException::invalidReturnTypeInTool($this->name, new TypeError('Return value must be of type string'));
            }

            return $value;
        } catch (ArgumentCountError|Error|InvalidArgumentException|TypeError $e) {
            if ($e::class === Error::class && ! str_starts_with($e->getMessage(), 'Unknown named parameter')) {
                throw $e;
            }

            throw PrismException::invalidParameterInTool($this->name, $e);
        }
    }
}
