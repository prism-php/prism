<?php

declare(strict_types=1);

namespace Prism\Prism;

use ArgumentCountError;
use Closure;
use Error;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Concerns\HasProviderOptions;
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
    use HasProviderOptions;

    protected string $name = '';

    protected string $description;

    /** @var array<string,Schema> */
    protected array $parameters = [];

    /** @var array <int, string> */
    protected array $requiredParameters = [];

    /** @var Closure():string|callable():string */
    protected $fn;

    /** @var null|Closure(Throwable,array<int|string,mixed>):string */
    protected ?Closure $failedHandler = null;

    public function __construct()
    {
        // Initialize with default error handler if globally enabled
        if ($this->isErrorHandlingEnabled()) {
            $this->failedHandler = fn (Throwable $e, array $params): string => $this->getDefaultFailedMessage($e, $params);
        }
    }

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

    /**
     * @param  Closure(Throwable,array<int|string,mixed>):string  $handler
     */
    public function failed(Closure $handler): self
    {
        $this->failedHandler = $handler;

        return $this;
    }

    /**
     * Disable tool error handling and revert to throwing exceptions.
     */
    public function withoutErrorHandling(): self
    {
        $this->failedHandler = null;

        return $this;
    }

    public function withParameter(Schema $parameter, bool $required = true): self
    {
        $this->parameters[$parameter->name()] = $parameter;

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
     * @return array<string,Schema>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function parametersAsArray(): array
    {
        return Arr::mapWithKeys($this->parameters, fn (Schema $schema, string $name): array => [
            $name => $schema->toArray(),
        ]);
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
     * @return null|Closure(Throwable,array<int|string,mixed>):string
     */
    public function failedHandler(): ?Closure
    {
        return $this->failedHandler;
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
        } catch (Throwable $e) {
            // If we have a failed handler, use it instead of throwing
            if ($this->failedHandler instanceof Closure) {
                // Extract the provided parameters for context
                $providedParams = $this->extractProvidedParams($args);

                return ($this->failedHandler)($e, $providedParams);
            }

            // Otherwise, maintain backward compatibility
            if ($e instanceof TypeError || $e instanceof InvalidArgumentException) {
                throw PrismException::invalidParameterInTool($this->name, $e);
            }

            if ($e::class === Error::class && ! str_starts_with($e->getMessage(), 'Unknown named parameter')) {
                throw $e;
            }

            if (str_starts_with($e->getMessage(), 'Unknown named parameter')) {
                throw PrismException::invalidParameterInTool($this->name, $e);
            }

            // Re-throw other exceptions
            throw $e;
        }
    }

    /**
     * @param  array<int|string,mixed>  $providedParams
     */
    protected function getDefaultFailedMessage(Throwable $e, array $providedParams): string
    {
        $expectedParams = collect($this->parameters)
            ->map(fn (Schema $param): string => sprintf(
                '%s (%s%s)',
                $param->name(),
                class_basename($param),
                in_array($param->name(), $this->requiredParameters) ? ', required' : ''
            ))
            ->join(', ');

        // Differentiate between validation errors (AI gave wrong types/names) and runtime errors
        // Note: ArgumentCountError extends TypeError, so we only need to check TypeError
        $isValidationError = $e instanceof TypeError
            || ($e instanceof Error && str_contains($e->getMessage(), 'Unknown named parameter'));

        if ($isValidationError) {
            $errorType = match (true) {
                $e instanceof ArgumentCountError => 'Missing required parameters',
                $e instanceof TypeError && str_contains($e->getMessage(), 'must be of type') => 'Type mismatch',
                str_contains($e->getMessage(), 'Unknown named parameter') => 'Unknown parameters',
                default => 'Invalid parameters',
            };

            return sprintf(
                'Parameter validation error: %s. Expected: [%s]. Received: %s. Please provide correct parameter types and names.',
                $errorType,
                $expectedParams,
                json_encode($providedParams)
            );
        }

        // For runtime errors (e.g., file not found, API failures), provide different format
        return sprintf(
            'Tool execution error: %s. This error occurred during tool execution, not due to invalid parameters.',
            $e->getMessage()
        );
    }

    /**
     * @param  array<int|string,mixed>  $args
     * @return array<int|string,mixed>
     */
    protected function extractProvidedParams(array $args): array
    {
        // If args is already an associative array (from tool calls), return as is
        if (! array_is_list($args)) {
            return $args;
        }

        // Otherwise map positional args to parameter names
        $paramNames = array_keys($this->parameters);
        $result = [];

        foreach ($args as $index => $value) {
            if (isset($paramNames[$index])) {
                $result[$paramNames[$index]] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if error handling is enabled globally.
     */
    protected function isErrorHandlingEnabled(): bool
    {
        // Check if we're in a Laravel environment and respect the config
        if (function_exists('config')) {
            return config('prism.tool_error_handling', true);
        }

        // Default to enabled if not in Laravel
        return true;
    }
}
