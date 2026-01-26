<?php

declare(strict_types=1);

namespace Prism\Prism;

use ArgumentCountError;
use Closure;
use Error;
use Illuminate\Container\Container;
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
use Prism\Prism\Tools\LaravelMcpTool;
use Prism\Prism\ValueObjects\ToolOutput;
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

    /** @var Closure():mixed|callable():mixed */
    protected $fn;

    /** @var null|false|Closure(Throwable,array<int|string,mixed>):string */
    protected null|false|Closure $failedHandler = null;

    protected bool $concurrent = false;

    public function __construct()
    {
        //
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

    public function make(string|object $tool): Tool
    {
        if (is_string($tool)) {
            $tool = Container::getInstance()->make($tool);
        }

        if ($tool instanceof Tool) {
            return $tool;
        }

        if ($tool instanceof \Laravel\Mcp\Server\Tool) {
            return new LaravelMcpTool($tool);
        }

        throw new InvalidArgumentException('Invalid tool provided: '.$tool::class);
    }

    /**
     * @param  Closure(Throwable,array<int|string,mixed>):string  $handler
     */
    public function failed(Closure $handler): self
    {
        $this->failedHandler = $handler;

        return $this;
    }

    public function withoutErrorHandling(): self
    {
        $this->failedHandler = false;

        return $this;
    }

    public function withErrorHandling(?Closure $handler = null): self
    {
        $this->failedHandler = $handler;

        return $this;
    }

    public function concurrent(bool $concurrent = true): self
    {
        $this->concurrent = $concurrent;

        return $this;
    }

    public function isConcurrent(): bool
    {
        return $this->concurrent;
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
     * @return null|false|Closure(Throwable,array<int|string,mixed>):string
     */
    public function failedHandler(): null|false|Closure
    {
        return $this->failedHandler;
    }

    /**
     * @param  string|int|float  $args
     *
     * @throws PrismException|Throwable
     */
    public function handle(...$args): string|ToolOutput
    {
        try {
            $value = call_user_func($this->fn, ...$args);

            if (is_string($value)) {
                return $value;
            }

            if ($value instanceof ToolOutput) {
                return $value;
            }

            throw PrismException::invalidReturnTypeInTool(
                $this->name,
                new TypeError('Return value must be of type string or ToolOutput')
            );
        } catch (Throwable $e) {
            return $this->handleToolException($e, $args);
        }
    }

    protected function shouldHandleErrors(): bool
    {
        return $this->failedHandler !== false;
    }

    protected function hasCustomErrorHandler(): bool
    {
        return $this->failedHandler instanceof Closure;
    }

    protected function shouldUseDefaultErrorHandling(): bool
    {
        return $this->shouldHandleErrors() && ! $this->hasCustomErrorHandler();
    }

    /**
     * @param  array<int|string,mixed>  $providedParams
     */
    protected function getDefaultFailedMessage(Throwable $e, array $providedParams): string
    {
        $errorType = $this->classifyToolError($e);

        return match ($errorType) {
            'validation' => $this->formatValidationError($e, $providedParams),
            'runtime' => $this->formatRuntimeError($e),
            default => $this->formatRuntimeError($e),
        };
    }

    protected function classifyToolError(Throwable $e): string
    {
        $isValidationError = $e instanceof TypeError
            || ($e instanceof Error && str_contains($e->getMessage(), 'Unknown named parameter'));

        return $isValidationError ? 'validation' : 'runtime';
    }

    /**
     * @param  array<int|string,mixed>  $providedParams
     */
    protected function formatValidationError(Throwable $e, array $providedParams): string
    {
        $errorType = $this->determineValidationErrorType($e);
        $expectedParams = $this->formatExpectedParameters();
        $receivedParams = $this->formatReceivedParameters($providedParams);

        return sprintf(
            'Parameter validation error: %s. Expected: [%s]. Received: %s. Please provide correct parameter types and names.',
            $errorType,
            $expectedParams,
            $receivedParams
        );
    }

    protected function formatRuntimeError(Throwable $e): string
    {
        return sprintf(
            'Tool execution error: %s. This error occurred during tool execution, not due to invalid parameters.',
            $e->getMessage()
        );
    }

    protected function determineValidationErrorType(Throwable $e): string
    {
        return match (true) {
            $e instanceof ArgumentCountError => 'Missing required parameters',
            $e instanceof TypeError && str_contains($e->getMessage(), 'must be of type') => 'Type mismatch',
            str_contains($e->getMessage(), 'Unknown named parameter') => 'Unknown parameters',
            default => 'Invalid parameters',
        };
    }

    protected function formatExpectedParameters(): string
    {
        return collect($this->parameters)
            ->map(fn (Schema $param): string => sprintf(
                '%s (%s%s)',
                $param->name(),
                class_basename($param),
                in_array($param->name(), $this->requiredParameters) ? ', required' : ''
            ))
            ->join(', ');
    }

    /**
     * @param  array<int|string,mixed>  $providedParams
     */
    protected function formatReceivedParameters(array $providedParams): string
    {
        return json_encode($providedParams) ?: '{}';
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
     * @param  array<int|string,mixed>  $args
     *
     * @throws PrismException|Throwable
     */
    protected function handleToolException(Throwable $e, array $args): string
    {
        if ($this->hasCustomErrorHandler()) {
            $providedParams = $this->extractProvidedParams($args);

            /** @var Closure(Throwable,array<int|string,mixed>):string $handler */
            $handler = $this->failedHandler;

            return $handler($e, $providedParams);
        }

        if (! $this->shouldHandleErrors()) {
            $this->throwMappedException($e);
        }

        if ($this->shouldUseDefaultErrorHandling()) {
            $providedParams = $this->extractProvidedParams($args);

            return $this->getDefaultFailedMessage($e, $providedParams);
        }

        throw $e;
    }

    /**
     * @throws PrismException|Throwable
     */
    protected function throwMappedException(Throwable $e): never
    {
        if ($e instanceof TypeError || $e instanceof InvalidArgumentException) {
            throw PrismException::invalidParameterInTool($this->name, $e);
        }

        if ($e::class === Error::class && ! str_starts_with($e->getMessage(), 'Unknown named parameter')) {
            throw $e;
        }

        if (str_starts_with($e->getMessage(), 'Unknown named parameter')) {
            throw PrismException::invalidParameterInTool($this->name, $e);
        }

        throw $e;
    }
}
