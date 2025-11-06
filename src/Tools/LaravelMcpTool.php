<?php

namespace Prism\Prism\Tools;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Support\ValidationMessages;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

class LaravelMcpTool extends Tool
{
    public function __construct(private readonly \Laravel\Mcp\Server\Tool $tool)
    {
        $this->as($tool->name())
            ->for($tool->description())
            ->using($this);

        $data = $tool->toArray();
        $properties = $data['inputSchema']['properties'] ?? [];
        $required = $data['inputSchema']['required'] ?? [];

        foreach ($properties as $name => $property) {
            $this->withParameter(new RawSchema($name, $property), in_array($name, $required, true));
        }
    }

    /**
     * @phpstan-ignore missingType.parameter
     */
    public function __invoke(...$args): string
    {
        // Set default values for parameters that are not provided
        $properties = $this->parametersAsArray();
        foreach ($properties as $name => $property) {
            if (! isset($args[$name]) && isset($property['default'])) {
                $args[$name] = $property['default'];
            }
        }

        $request = new Request($args);

        try {
            /**
             * @var Response $response
             *
             * @phpstan-ignore method.notFound
             */
            $response = $this->tool->handle($request);
        } catch (ValidationException $validationException) {
            $response = Response::error(ValidationMessages::from($validationException));
        }

        return (string) $response->content();
    }
}
