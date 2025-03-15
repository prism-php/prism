# Tools & Function Calling

Need your AI assistant to check the weather, search a database, or call your API? Tools are here to help! They let you extend your AI's capabilities by giving it access to specific functions it can call.

## Tool Concept Overview

Think of tools as special functions that your AI assistant can use when it needs to perform specific tasks. Just like how Laravel's facades provide a clean interface to complex functionality, Prism tools give your AI a clean way to interact with external services and data sources.

```php
use Prism\Prism\Prism;
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
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();
```

## Max Steps

Prism defaults to allowing a single step. To use Tools, you'll need to increase this using `withMaxSteps`:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    // Increase max steps to at least 2
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();
```

You should use a higher number of max steps if you expect your initial prompt to make multiple tool calls.

## Creating Basic Tools

Creating tools in Prism is straightforward and fluent. Here's how you can create a simple tool:

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

Tools can take a variety of parameters, but must always return a string.

## Parameter Definition

Prism offers multiple ways to define tool parameters, from simple primitives to complex objects.

### String Parameters

Perfect for text inputs:

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
        return "Calculated result: {$value * 2}";
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
            ->withStringParameter('query', 'Detailed search query. Best to search one topic at a time.')
            ->using($this);
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

## Tool Choice Options

You can control how the AI uses tools with the `withToolChoice` method:
```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;

$prism = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
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

## Response Handling with Tools

When your AI uses tools, you can inspect the results and see how it arrived at its answer:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2)
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();

// Get the final answer
echo $response->text;

// ->text is empty for tool calls

// Inspect tool usage

if ($response->toolResults) {
    foreach ($response->toolResults as $toolResult) {
        echo "Tool: " . $toolResult->toolName . "\n";
        echo "Result: " . $toolResult->result . "\n";
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
