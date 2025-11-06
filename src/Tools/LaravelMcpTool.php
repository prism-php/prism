<?php

namespace Prism\Prism\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

class LaravelMcpTool extends Tool
{
    public function __construct(private readonly \Laravel\Mcp\Server\Tool $tool)
    {
        $this->as($tool->name())
            ->for($tool->description())
            ->using($this);

        $inputSchema = $tool->toArray()['inputSchema'];
        $properties = $inputSchema['properties'] ?? [];
        $required = $inputSchema['required'] ?? [];

        foreach ($properties as $name => $property) {
            $this->withParameter(new RawSchema($name, $property), in_array($name, $required, true));
        }
    }

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

        /** @var Response $response */
        $response = $this->tool->handle($request);

        return (string) $response->content();
    }
}
