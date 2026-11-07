# Tools & Function Calling

Tools let a model request application-defined functions, such as database queries or API calls. Your application executes the function and returns its result to the model.

## Tool Concept Overview

Define each tool's name, description, parameters and handler. Prism sends the definition to the provider and uses the handler to execute requested calls.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('weather')
    ->for('Get current weather conditions')
    ->withStringParameter('city', 'The city to get weather for')
    ->using(function (string $city): string {
        // Your weather API logic here
        return "The weather in {$city} is sunny and 72°F.";
    });

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();
```

## Max Steps

Prism defaults to allowing a single step. To use Tools, you'll need to increase this using `withMaxSteps`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    // Increase max steps to at least 2
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();
```

You should use a higher number of max steps if you expect your initial prompt to make multiple tool calls.

## Creating Basic Tools

Create a tool with the fluent builder:

```php
use Prism\Prism\Facades\Tool;

$searchTool = Tool::as('search')
    ->for('Search for current information')
    ->withStringParameter('query', 'The search query')
    ->using(function (string $query): string {
        // Your search implementation
        return "Search results for: {$query}";
    });
```

Return a string for a text result, or a `ToolOutput` to include application artifacts alongside the result.

## Error Handling

By default, a tool's `handle()` method catches failures and returns a `ToolError` with a `message`; it does not propagate the handler exception. Provider tool loops send this failure back to the model. Code that invokes `handle()` directly must distinguish `ToolError` from a successful string or `ToolOutput`.

```php
$tool = Tool::as('calculate')
    ->for('Add two numbers')
    ->withNumberParameter('a', 'First number')
    ->withNumberParameter('b', 'Second number')
    ->using(fn (int $a, int $b): string => (string) ($a + $b));

// If AI provides invalid parameters, it receives:
// "Parameter validation error: Type mismatch. Expected: [a (NumberSchema, required), b (NumberSchema, required)]. Received: {"a":"five","b":10}"
```

Use `failed()` to replace the message sent to the model:

```php
use Prism\Prism\ValueObjects\ToolError;

$tool->failed(fn (Throwable $error, array $arguments): string => 'The operation failed.');
$result = $tool->handle(a: 2, b: 3);

if ($result instanceof ToolError) {
    // Handle the failure; do not treat it as a successful result.
}
```

`withErrorHandling($callback)` also sets a failure callback; `withErrorHandling()` without a callback restores the application default, or the built-in handler when no application handler is registered. Built-in failure messages can contain exception details and supplied arguments. Use a redacted callback when these can include secrets or personal data.

### Application-wide failure handler

Register a default for thrown tool errors in your application's service provider `boot()`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

public function boot(): void
{
    Prism::handleToolErrorsUsing(function (\Throwable $error, Tool $tool, array $params): string {
        report($error);

        return 'The operation is temporarily unavailable. Please try again later.';
    });
}
```

The callback receives the original exception, tool and supplied parameters. Return only text suitable for the model and the person using your application. A tool's `failed()` callback takes precedence, followed by the application handler, then the built-in handler. Tools configured with `withoutErrorHandling()` continue to propagate exceptions instead.

If the application callback throws or returns anything other than a string, Prism returns `The tool failed.` without falling back to exception details. Returned tool results are unchanged; use [result classification](/advanced/telemetry#tool-failures) for tools that return their own failure values. `ToolInvoked.failed` and `failure` retain their existing meanings; handled exceptions remain `tool_error`.

The registration belongs to the current PHP process and lasts until replaced or cleared with `Prism::handleToolErrorsUsing(null)`. Register it in a service provider's `boot()` so every HTTP, queue and Laravel process-concurrency worker installs the policy. A handler registered only at runtime in one process does not exist in process-concurrency children; tools there use the next handler in precedence order, which can include the built-in message with exception details. Request-specific handlers must be cleared after use in long-lived workers. Prism does not serialize this application callback with tools.

### Opting Out

If you prefer exceptions for invalid parameters:

```php
// Per-tool
$tool->withoutErrorHandling();

// Then pass the configured tool to a text or structured request.
```

`withoutToolErrorHandling()` on a text request disables custom failure callbacks, but leaves tools using the default failure handler unchanged. Its structured-request flag does not alter the supplied tools. For consistent exception behavior, configure `withoutErrorHandling()` on each tool. Text request configuration can mutate a tool carrying a custom callback; use a separate tool instance when requests need different policies.

## Parameter Definition

Prism offers multiple ways to define tool parameters, from simple primitives to complex objects.

### String Parameters

For text inputs:

```php
use Prism\Prism\Facades\Tool;

$tool = Tool::as('search')
    ->for('Search for information')
    ->withStringParameter('query', 'The search query')
    ->using(function (string $query): string {
        return "Search results for: {$query}";
    });
```

### Number Parameters

For integer or floating-point values:

```php
use Prism\Prism\Facades\Tool;

$tool = Tool::as('calculate')
    ->for('Perform calculations')
    ->withNumberParameter('value', 'The number to process')
    ->using(function (float $value): string {
        return 'Calculated result: '.($value * 2);
    });
```

### Boolean Parameters

For true/false flags:

```php
use Prism\Prism\Facades\Tool;

$tool = Tool::as('feature_toggle')
    ->for('Toggle a feature')
    ->withBooleanParameter('enabled', 'Whether to enable the feature')
    ->using(function (bool $enabled): string {
        return "Feature is now " . ($enabled ? 'enabled' : 'disabled');
    });
```

### Array Parameters

For handling lists of items:

```php
use Prism\Prism\Facades\Tool;

$tool = Tool::as('process_tags')
    ->for('Process a list of tags')
    ->withArrayParameter(
        'tags',
        'List of tags to process',
        new StringSchema('tag', 'A single tag')
    )
    ->using(function (array $tags): string {
        return "Processing tags: " . implode(', ', $tags);
    });
```

### Enum Parameters

When you need to restrict values to a specific set:

```php
use Prism\Prism\Facades\Tool;

$tool = Tool::as('set_status')
    ->for('Set the status')
    ->withEnumParameter(
        'status',
        'The new status',
        ['draft', 'published', 'archived']
    )
    ->using(function (string $status): string {
        return "Status set to: {$status}";
    });
```

### Object Parameters

Parameter helpers default to `required: true`. Set `required: false` to make a parameter optional, and give the corresponding handler argument a default value. `withObjectParameter()` accepts both inner `requiredFields` and the outer parameter's `required` flag, plus `allowAdditionalProperties` (default false). These describe the schema; validate and authorize values in your handler before acting on them.

For complex objects without needing to create separate schema instances:

```php
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$tool = Tool::as('update_user')
    ->for('Update a user profile')
    ->withObjectParameter(
        'user',
        'The user profile data',
        [
            new StringSchema('name', 'User\'s full name'),
            new NumberSchema('age', 'User\'s age'),
            new StringSchema('email', 'User\'s email address')
        ],
        requiredFields: ['name', 'email']
    )
    ->using(function (array $user): string {
        return "Updated user profile for: {$user['name']}";
    });
```

### Schema-based Parameters

For complex, nested data structures, you can use Prism's schema system:

```php
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$tool = Tool::as('create_user')
    ->for('Create a new user profile')
    ->withParameter(new ObjectSchema(
        name: 'user',
        description: 'The user profile data',
        properties: [
            new StringSchema('name', 'User\'s full name'),
            new NumberSchema('age', 'User\'s age'),
            new StringSchema('email', 'User\'s email address')
        ],
        requiredFields: ['name', 'email']
    ))
    ->using(function (array $user): string {
        return "Created user profile for: {$user['name']}";
    });
```

> [!TIP]
> For more complex parameter definitions, Prism provides a powerful schema system. See our [complete schemas guide](/core-concepts/schemas) to learn how to define complex nested objects, arrays, enums, and more.

## Complex Tool Implementation

For more sophisticated tools, you can create dedicated classes:

```php
namespace App\Tools;

use Prism\Prism\Tool;
use Illuminate\Support\Facades\Http;

class SearchTool extends Tool
{
    public function __construct()
    {
        $this
            ->as('search')
            ->for('useful when you need to search for current events')
            ->withStringParameter('query', 'Detailed search query. Best to search one topic at a time.');
    }

    public function __invoke(string $query): string
    {
        $response = Http::get('https://serpapi.com/search', [
            'engine' => 'google',
            'q' => $query,
            'google_domain' => 'google.com',
            'gl' => 'us',
            'hl' => 'en',
            'api_key' => config('services.serpapi.api_key'),
        ]);

        $results = collect($response->json('organic_results'));

        $results->map(function ($result) {
            return [
                'title' => $result['title'],
                'link' => $result['link'],
                'snippet' => $result['snippet'],
            ];
        })->take(4);

        return view('prompts.search-tool-results', [
            'results' => $results,
        ])->render();
    }
}
```

You can use `Tool::make($className)` if you need to resolve the dependencies:


```php
use App\Tools\SearchTool;
use Prism\Prism\Facades\Tool;

$tool = Tool::make(SearchTool::class);
```

### Generating a Tool Class

Generate a tool class with Artisan:

```bash
php artisan make:prism-tool SearchTool
```

This creates `app/Tools/SearchTool.php` with the model-facing name `search`.
The generator removes the `Tool` suffix and converts the name to snake_case.

Declare parameters to generate both their schemas and a matching `__invoke()` signature:

```bash
php artisan make:prism-tool SearchTool     --description="Search the web for current events"     --parameter="query:string:What to search for"     --parameter="scope:enum(web,news,images):Which index to search"     --parameter="limit:integer?:How many results to return"
```

```php
$this
    ->as('search')
    ->for('Search the web for current events')
    ->withStringParameter('query', 'What to search for')
    ->withEnumParameter('scope', 'Which index to search', ['web', 'news', 'images'])
    ->withNumberParameter('limit', 'How many results to return', required: false);
```

```php
interface SearchHandler
{
    public function __invoke(string $query, string $scope, ?int $limit = null): string;
}
```

Each `--parameter` is `name:type[:description]`. The types are `string`,
`number`, `integer`, `boolean` and `enum(a,b,c)` — suffix any of them with `?`
to make the parameter optional. Optional parameters are always emitted last
regardless of the order you list them in, since PHP won't accept a required
argument after an optional one.

> [!TIP]
> The generator does not accept array or object parameters. Add their schemas
> to the generated class with `withParameter()`; see the [schemas guide](/core-concepts/schemas).

Publish the stub to customize generated classes:

```bash
php artisan vendor:publish --tag=prism-stubs
```

> [!NOTE]
> `make:prism-tool` generates a tool for Prism's `withTools()` API.
> Laravel MCP's `make:mcp-tool` generates a tool exposed by an MCP server.

## Concurrent Tool Execution

When the AI calls multiple tools in a single step, Prism normally executes them sequentially. For I/O-bound operations like API calls or database queries, you can enable concurrent execution to run tools in parallel, reducing total wait time.

### Marking Tools as Concurrent

Use the `concurrent()` method to mark a tool as safe for parallel execution:

```php
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('weather')
    ->for('Get current weather conditions')
    ->withStringParameter('city', 'The city to get weather for')
    ->using(function (string $city): string {
        // API call that takes ~500ms
        return Http::get("https://api.weather.com/{$city}")->json('conditions');
    })
    ->concurrent();

$stockTool = Tool::as('stock_price')
    ->for('Get current stock price')
    ->withStringParameter('symbol', 'The stock ticker symbol')
    ->using(function (string $symbol): string {
        // Another API call that takes ~500ms
        return Http::get("https://api.stocks.com/{$symbol}")->json('price');
    })
    ->concurrent();
```

When both tools are requested in one step, they execute in parallel. Actual latency depends on the operations and concurrency driver.

### How It Works

Prism uses [Laravel's Concurrency facade](https://laravel.com/docs/12.x/concurrency) to execute concurrent tools. Under the hood, tools marked as concurrent are grouped and run in parallel, while sequential tools run one at a time.

The execution flow:
1. Prism groups tool calls by their concurrency setting
2. Concurrent tools execute in parallel via `Concurrency::run()`
3. Sequential tools execute one at a time
4. Results are returned in the original order, regardless of execution order

### When to Use Concurrent Tools

**Good candidates for concurrent execution:**
- External API calls (weather, stocks, search)
- Database queries that don't depend on each other
- File reads from different sources
- Any I/O-bound operation

**Keep sequential (don't mark as concurrent):**
- Tools that modify shared state
- Tools where execution order matters
- Tools with side effects that could conflict
- CPU-bound operations (concurrency won't help)

### Mixed Execution

You can mix concurrent and sequential tools in the same request:

```php
$searchTool = Tool::as('search')
    ->for('Search the web')
    ->withStringParameter('query', 'Search query')
    ->using(fn (string $query): string => $this->search($query))
    ->concurrent(); // Safe to run in parallel

$saveResultTool = Tool::as('save_result')
    ->for('Save a result to the database')
    ->withStringParameter('data', 'Data to save')
    ->using(fn (string $data): string => $this->save($data));
    // Sequential - modifies database state
```

Prism handles the grouping automatically. Concurrent tools run in parallel, then sequential tools run in order.

### Error Handling

Handled tool errors are returned as tool results in the original call order. An unhandled native exception aborts the turn: partial results are discarded and no step is recorded. Completed siblings may still emit telemetry before the exception is rethrown. See [tool failure telemetry](/advanced/telemetry) for failure flags and content capture settings.

> [!NOTE]
> Configure the appropriate [Laravel concurrency driver](https://laravel.com/docs/12.x/concurrency). Prism requires `laravel/serializable-closure` `^1.3.4|^2.0` so process workers can receive readonly tool-call array arguments and reasoning summaries. If your application locks an older version, update it together with Prism using `composer update particle-academy/prism laravel/serializable-closure --with-all-dependencies`.

## Using Laravel MCP Tools

Install the optional dependency first with `composer require laravel/mcp`. It is not a production dependency of Prism core.
You can use existing [Laravel MCP](https://github.com/laravel/mcp) Tools in Prism directly, without using the Laravel MCP Server:

```php
use App\Mcp\Tools\CurrentWeatherTool;
use Prism\Prism\Facades\Tool;

$tool = Tool::make(CurrentWeatherTool::class);
```

Tools that define a [`shouldRegister`](https://laravel.com/docs/13.x/mcp#conditional-tool-registration) method are honored: when it returns `false`, the tool is excluded by `withTools()` and is never sent to the provider.

## Tool Choice Options

You can control how the AI uses tools with the `withToolChoice` method:
```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;

$prism = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withMaxSteps(2)
    ->withPrompt('How is the weather in Paris?')
    ->withTools([$weatherTool])
    // Let the AI decide whether to use tools
    ->withToolChoice(ToolChoice::Auto)
    // Force the AI to use a tool
    ->withToolChoice(ToolChoice::Any)
    // Force the AI to use a specific tool
    ->withToolChoice('weather');
```

> [!WARNING]
> Tool choice support varies by provider. Check your provider's documentation for specific capabilities.

`ToolChoice::None` requests no tool use on providers that support it. The other choices are `Auto`, `Any`, or a tool name/Tool instance. Each `withToolChoice()` call replaces the preceding selection; the chain above demonstrates alternatives. To request strict function schemas on a compatible provider, set `withProviderOptions(['strict' => true])` on the individual tool.

## Response Handling with Tools

When your AI uses tools, you can inspect the results and see how it arrived at its answer:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();

// Get the final answer
echo $response->text;

// ->text is empty for tool calls

// Inspect tool usage

foreach ($response->steps as $step) {
    foreach ($step->toolResults as $toolResult) {
        echo "Tool: " . $toolResult->toolName . "\n";
        echo "Result: " . json_encode($toolResult->result) . "\n";
    }
}


foreach ($response->steps as $step) {
    if ($step->toolCalls) {
        foreach ($step->toolCalls as $toolCall) {
            echo "Tool: " . $toolCall->name . "\n";
            echo "Arguments: " . json_encode($toolCall->arguments()) . "\n";
        }
    }
}
```

Text-response `toolCalls` and `toolResults` contain the final step's activity; a normal final answer often has neither. Read earlier calls, results and artifacts from `steps`. Structured responses aggregate those fields across steps.

For raw tool arguments, `arguments()` returns an associative PHP array. Use `argumentsAsObject()` when serializing an argument object, especially an empty object or numeric property names; `argumentsAsJson()` encodes that object as JSON. Invalid argument JSON raises `PrismException` when decoded.

Provider-side calls are separate from your PHP tools: inspect `$step->providerToolCalls` on text steps when the provider populates them. To defer a custom call to your application, use `clientExecuted()`; to pause execution for an approval, use `requiresApproval()`. See [Human-in-the-Loop](/core-concepts/human-in-the-loop) for continuation and authorization requirements.

## Tool Artifacts

Use artifacts for images, audio, files or structured data that your application
needs but that should not be included in the model's text context.

Return a `ToolOutput` that separates the text result (for the LLM) from artifacts (for your application):

```php
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\ToolOutput;

$imageTool = Tool::as('generate_image')
    ->for('Generate an image from a prompt')
    ->withStringParameter('prompt', 'The image prompt')
    ->using(function (string $prompt): ToolOutput {
        // Your image generation logic
        $imageData = $this->imageGenerator->generate($prompt);

        return new ToolOutput(
            result: json_encode(['status' => 'success', 'description' => $prompt]),
            artifacts: [
                Artifact::fromRawContent(
                    content: $imageData,
                    mimeType: 'image/png',
                    metadata: ['width' => 1024, 'height' => 1024],
                    id: 'generated-image-001',
                ),
            ],
        );
    });
```

The `result` goes to the LLM. The `artifacts` travel through the streaming system to your application.

### Creating Artifacts

The `Artifact` class represents binary or structured data:

```php
use Prism\Prism\ValueObjects\Artifact;

// From raw content (automatically base64 encoded)
$artifact = Artifact::fromRawContent(
    content: file_get_contents('image.png'),
    mimeType: 'image/png',
    metadata: ['width' => 800, 'height' => 600],
    id: 'my-image-id',
);

// From already base64-encoded data
$artifact = new Artifact(
    data: base64_encode($rawData),
    mimeType: 'application/pdf',
    metadata: ['pages' => 5],
    id: 'report-001',
);

// Get raw content back
$rawContent = $artifact->rawContent();
```

### Handling Artifacts in Streams

Artifacts are emitted as `ArtifactEvent` through all streaming methods. These examples use the application-owned `$imageTool` above and an existing writable `output/` directory. Generate storage filenames yourself; tool names and artifact IDs are identifiers, not trusted paths. The callback example assumes your application has an `Attachment` model and an authorized `$conversationId`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ArtifactEvent;

// Using asStream()
foreach (Prism::text()->using('anthropic', 'claude-sonnet-5-5')->withPrompt('Generate an image of a sunset')->withTools([$imageTool])->withMaxSteps(2)->asStream() as $event) {
    if ($event instanceof ArtifactEvent) {
        $artifact = $event->artifact;
        file_put_contents(
            'output/'.bin2hex(random_bytes(16)).'.bin',
            $artifact->rawContent()
        );
    }
}

// Using asDataStreamResponse() with callback
Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$imageTool])
    ->withPrompt('Generate an image of a sunset')
    ->withMaxSteps(2)
    ->asDataStreamResponse(function ($pendingRequest, $events) use ($conversationId) {
        foreach ($events as $event) {
            if ($event instanceof ArtifactEvent) {
                Attachment::create([
                    'conversation_id' => $conversationId,
                    'data' => $event->artifact->rawContent(),
                    'mime_type' => $event->artifact->mimeType,
                ]);
            }
        }
    });
```

### Non-Streaming Mode

In non-streaming mode, artifacts are available on the `ToolResult` objects:

```php
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$imageTool])
    ->withMaxSteps(3)
    ->withPrompt('Generate an image of a sunset')
    ->asText();

foreach ($response->steps->flatMap(fn ($step) => $step->toolResults) as $result) {
    if ($result->hasArtifacts()) {
        foreach ($result->artifacts as $artifact) {
            // Process artifact
            file_put_contents(
                'output/'.bin2hex(random_bytes(16)).'.bin',
                $artifact->rawContent()
            );
        }
    }
}
```

### Backward Compatibility

Tools returning `string` continue to work unchanged. The `ToolOutput` return type is optional:

```php
// Both are valid:
$tool->using(fn (string $query): string => "Result: {$query}");
$tool->using(fn (string $query): ToolOutput => new ToolOutput(result: "Result: {$query}"));
```

## Provider Tools

In addition to custom tools that you define, Prism supports **provider tools** - built-in capabilities offered directly by AI providers. These are specialized tools that leverage the provider's own infrastructure and services.

### Understanding Provider Tools vs Custom Tools

**Custom Tools** (covered above) are functions you define and implement yourself:
- You control the logic and implementation
- Called by the AI, executed by your code
- Can access your databases, APIs, and services

**Provider Tools** are built-in capabilities offered by the AI provider:
- Implemented and executed by the provider
- Access the provider's own services and infrastructure
- Enable capabilities like code execution, web search, and more

### Using Provider Tools

Provider tools are added to your requests using the `withProviderTools()` method with `ProviderTool` objects:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt('Calculate the fibonacci sequence up to 100')
    ->withProviderTools([
        new ProviderTool(type: 'code_execution_20250825', name: 'code_execution')
    ])
    ->asText();
```

### Available Provider Tools

Each provider offers different built-in capabilities. Check the provider-specific documentation for detailed information about available tools, configuration options, and usage examples.

### ProviderTool Object

The `ProviderTool` class accepts three parameters:

```php
new ProviderTool(
    type: 'code_execution_20250825',  // Required: The provider tool identifier
    name: 'code_execution',           // Optional: Custom name for the tool
    options: []                       // Optional: Provider-specific options
);
```

- **type**: The provider-specific tool identifier (required)
- **name**: Optional custom name for the tool
- **options**: Additional provider-specific configuration options

### Combining Provider Tools and Custom Tools

You can use both provider tools and custom tools in the same request:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\Facades\Tool;

$customTool = Tool::as('database_lookup')
    ->for('Look up user information')
    ->withStringParameter('user_id', 'The user ID to look up')
    ->using(function (string $userId): string {
        // Your database lookup logic
        return "User data for ID: {$userId}";
    });

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withMaxSteps(5)
    ->withPrompt('Look up user 123 and calculate their usage statistics')
    ->withTools([$customTool])
    ->withProviderTools([
        new ProviderTool(type: 'code_execution_20250825', name: 'code_execution')
    ])
    ->asText();
```

## Using Tools with Structured Output

Tools can be combined with structured output to gather data and return formatted results in a single request. This pattern is useful when you need the AI to call functions to fetch information, then format the results according to a specific schema.

### Basic Example

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Facades\Tool;

$schema = new ObjectSchema(
    name: 'weather_analysis',
    description: 'Analysis of weather conditions',
    properties: [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    requiredFields: ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Response contains both structured data and tool execution details
dump($response->structured);
```

> [!IMPORTANT]
> When combining tools with structured output, you must set `maxSteps` to at least 2. The AI needs multiple steps to call tools and then return structured output.

### Response Structure

Responses include both the structured output and tool execution details:

```php
// Final structured data
$data = $response->structured;

// All tool calls made during execution
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
}

// Tool execution results
foreach ($response->toolResults as $result) {
    echo "Result: {$result->result}\n";
}
```

> [!NOTE]
> Only the final step contains structured data. Intermediate steps contain tool calls and results, but no structured output.

For complete documentation on combining tools with structured output, see the [Structured Output](./structured-output.md#combining-structured-output-with-tools) documentation.
