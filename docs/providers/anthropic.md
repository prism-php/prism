# Anthropic
## Configuration

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
    // Include beta strings as a comma separated list.
    'anthropic_beta' => env('ANTHROPIC_BETA', null),
]
```
## Prompt caching

Anthropic's prompt caching feature allows you to drastically reduce latency and your API bill when repeatedly re-using blocks of content within five minutes or one hour of each other, depending on the Anthropic compatible TTL option you provide.

We support Anthropic prompt caching on:

- System Messages (text only)
- User Messages (Text, Image and PDF (pdf only))
- Assistant Messages (text only)
- Tools

The API for enabling prompt caching is the same for all, enabled via the `withProviderOptions()` method. Where a UserMessage contains both text and an image or document, both will be cached.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '1h'])
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->withTools([
        Tool::as('cache me')
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->asText();
```

If you prefer, you can use the `AnthropicCacheType` Enum like so:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

(new UserMessage('I am a long re-usable user message.'))->withProviderOptions(['cacheType' => AnthropicCacheType::ephemeral])
```
**Important:** To enable prompt caching:
- System messages must use `withSystemPrompt()` or `withSystemPrompts()` (Anthropic does not allow SystemMessages in the messages array)
- User and Assistant messages must use `withMessages()`
- Tools use `withTools()`
- All message types support caching via `withProviderOptions(['cacheType' => 'ephemeral'])`
- You cannot use `withPrompt()` for caching as it doesn't allow adding provider options to individual messages
- Anthropic supports two TTL options: `5m` (default) or `1h`. You can leave the `cacheTtl` unset and Anthropic will use the default TTL of `5m`.

### Tool result caching

In addition to caching prompts and tool definitions, Prism supports caching tool results. This is particularly useful when making multiple tool calls where results might be referenced repeatedly.

To enable tool result caching, use the `tool_result_cache_type` provider option on your request:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('anthropic', 'claude-3-5-sonnet-20241022')
    ->withMaxSteps(30)
    ->withTools([new WeatherTool()])
    ->withProviderOptions([
        'tool_result_cache_type' => 'ephemeral'
    ])
    ->withPrompt('Check the weather in New York, London, Tokyo, Paris, and Sydney')
    ->asText();
```

When multiple tool results are returned, Prism automatically applies caching to only the last result, which caches all preceding results as well. This avoids Anthropic's 4-cache-breakpoint limitation.

Please ensure you read Anthropic's [prompt caching documentation](https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching), which covers some important information on e.g. minimum cacheable tokens and message order consistency.

## Extended thinking

Claude Sonnet 3.7 supports an optional extended thinking mode, where it will reason before returning its answer. Please ensure your consider [Anthropic's own extended thinking documentation](https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking) before using extended thinking with caching and/or tools, as there are some important limitations and behaviours to be aware of.

### Enabling extended thinking and setting budget
Prism supports thinking mode for text and structured with the same API:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    // enable thinking
    ->withProviderOptions(['thinking' => ['enabled' => true]]) 
    ->asText();
```
By default Prism will set the thinking budget to the value set in config, or where that isn't set, the minimum allowed (1024).

You can overide the config (or its default) using `withProviderOptions`:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    // Enable thinking and set a budget
    ->withProviderOptions([
        'thinking' => [
            'enabled' => true, 
            'budgetTokens' => 2048
        ]
    ]);
```
Note that thinking tokens count towards output tokens, so you will be billed for them and your token budget must be less than the max tokens you have set for the request. 

If you expect a long response, you should ensure there's enough tokens left for the response - i.e. does (maxTokens - thinkingBudget) leave a sufficient remainder.

### Inspecting the thinking block

Anthropic returns the thinking block with its response. 

You can access it via the additionalContent property on either the Response or the relevant step.

On the Response (easiest if not using tools):

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['enabled' => true']]) 
    ->asText();

$response->additionalContent['thinking'];
```

On the Step (necessary if using tools, as Anthropic returns the thinking block on the ToolCall step):

```php
$tools = [...];

$response = Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withTools($tools)
    ->withMaxSteps(3)
    ->withPrompt('What time is the tigers game today and should I wear a coat?')
    ->withProviderOptions(['thinking' => ['enabled' => true]])
    ->asText();

$response->steps->first()->additionalContent->thinking;
```

### Extended output mode

Claude Sonnet 3.7 also brings extended output mode which increase the output limit to 128k tokens. 

This feature is currently in beta, so you will need to enable to by adding `output-128k-2025-02-19` to your Anthropic anthropic_beta config (see [Configuration](#configuration) above).

## Streaming

Claude supports streaming responses in real-time. All the standard streaming methods work with Anthropic models:

```php
// Stream events
$stream = Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('Write a story')
    ->asStream();

// Server-Sent Events
return Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Extended Thinking

When using extended thinking, the reasoning process streams separately from the final answer:

```php
use Prism\Prism\Enums\StreamEventType;

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::ThinkingDelta => echo "[Thinking] " . $event->delta,
        StreamEventType::TextDelta => echo $event->delta,
        default => null,
    };
}
```

For complete streaming documentation including Vercel Data Protocol and WebSocket broadcasting, see [Streaming Output](/core-concepts/streaming-output).

## Documents

Anthropic supports PDF, text and markdown documents. Note that Anthropic uses vision to process PDFs under the hood, and consequently there are some limitations detailed in their [feature documentation](https://docs.anthropic.com/en/docs/build-with-claude/pdf-support).

See the [Documents](/input-modalities/documents.html) on how to get started using them.

Anthropic also supports "custom content documents", separately documented below, which are primarily for use with citations.

### Custom content documents

Custom content documents are primarily for use with citations (see below), if you need citations to reference your own chunking strategy.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(["The grass is green.", "Flamingos are pink.", "The sky is blue."])
            ]
        )
    ])
    ->asText();
```

## Citations

Prism supports [Anthropic's citations feature](https://docs.anthropic.com/en/docs/build-with-claude/citations) for both text and structured. 

Please note however that due to Anthropic not supporting "native" structured output, and Prism's workaround for this, the output can be unreliable. You should therefore ensure you implement proper error handling for the scenario where Anthropic does not return a valid decodable schema.

## Code execution

Anthropic offers built-in code execution capabilities that allow your AI to run code in a secure environment. This is a provider tool that executes code using Anthropic's infrastructure. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

To enable code execution, you will first need to enable the beta feature.

Either in prism/config.php:

```php
        'anthropic' => [
            ...
            'anthropic_beta' => 'code-execution-2025-05-22',
        ],

```

Or in your env file (assuming config/prism.php reflects the default prism setup):

```
ANTHROPIC_BETA="code-execution-2025-05-22"
```

You may then use code execution as follows:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

Prism::text()
    ->using('anthropic', 'claude-3-5-haiku-latest')
    ->withPrompt('Solve the equation 3x + 10 = 14.')
    ->withProviderTools([new ProviderTool(type: 'code_execution_20250522', name: 'code_execution')])
    ->asText();

```

### Enabling citations

Anthropic require citations to be enabled on all documents in a request. To enable them, using the `withProviderOptions()` method when building your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(
                    chunks: ["The grass is green.", "Flamingos are pink.", "The sky is blue."],
                    title: 'The colours of nature',
                    context: 'The go-to textbook on the colours found in nature!'
                )
            ]
        )
    ])
    ->withProviderOptions(['citations' => true])
    ->asText();
```

### Accessing citations

You can access the chunked output with its citations via the additionalContent property on a response, which returns an array of `MessagePartWithCitations`s.

As a rough worked example, let's assume you want to implement footnotes. You'll need to loop through those chunks and (1) re-construct the message with links to the footnotes; and (2) build an array of footnotes to loop through in your frontend.

```php
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;

$messageChunks = $response->additionalContent['citations'];

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $messageChunk  */
foreach ($messageChunks as $messageChunk) {
    $text .= $messageChunk->outputText;
    
    /** @var Citation $citation */
    foreach ($messageChunk->citations as $citation) {
        $footnotes[] = [
            'id' => $footnoteId,
            'document_title' => $citation->sourceTitle,
            'reference_start' => $citation->sourceStartIndex,
            'reference_end' => $citation->sourceEndIndex
        ];
    
        $text .= '<sup><a href="#footnote-'.$footnoteId.'">'.$footnoteId.'</a></sup>';
    
        $footnoteId++;
    }
}
```

Note that when using streaming, Anthropic does not stream citations in the same way. Instead, of building the context as above, yield text to the browser in the usual way and pair text up with the relevant footnote using the `citationIndex` on the text chunk's additionalContent parameter.

## Considerations
### Message Order

- Message order matters. Anthropic is strict about the message order being:

1. `UserMessage`
2. `AssistantMessage`
3. `UserMessage`

### Structured Output

Prism supports three approaches for structured output with Anthropic models:

#### Native Structured Outputs (Recommended for Claude Sonnet 4.5+)

Claude Sonnet 4.5 and Opus 4.1 support native structured outputs through Anthropic's `output_format` parameter. This provides guaranteed schema compliance through constrained decoding.

To enable native structured outputs, set the beta header in your configuration:

```php
// In config/prism.php or .env
'anthropic' => [
    ...
    'anthropic_beta' => env('ANTHROPIC_BETA', 'structured-outputs-2025-11-13'),
]
```

Or in your `.env` file:
```
ANTHROPIC_BETA=structured-outputs-2025-11-13
```

Once enabled, Prism will automatically use native structured outputs when available:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```

**Benefits of native structured outputs:**
- **Always valid JSON**: No more parsing errors or malformed responses
- **Type safe**: Guaranteed field types and required fields

**Limitations:**
- Only available on Claude Sonnet 4.5+ and Claude Opus 4.1+
- Cannot be used with citations
- Some JSON Schema features are not supported (see [Schema Limitations](#schema-limitations))

#### Default JSON Mode (Prompt-based)
- We automatically append instructions to your prompt that guide the model to output valid JSON matching your schema
- If the response isn't valid JSON, Prism will raise a PrismException
- This method can sometimes struggle with complex JSON containing quotes, especially in non-English languages
- Used as fallback when native mode is not available

#### Tool Calling Mode
For more reliable structured output on older models, especially when dealing with complex content or non-English text that may contain quotes, you can enable tool calling mode:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->withProviderOptions(['use_tool_calling' => true])
    ->asStructured();
```

**Benefits of tool calling mode:**
- More reliable JSON parsing, especially with quotes and special characters
- Better handling of non-English content (Chinese, Japanese, etc.)
- Reduced risk of malformed JSON responses
- Compatible with thinking mode

**Limitations:**
- Cannot be used with citations (citations are not supported in tool calling mode)
- Slightly more complex under the hood but identical API usage

#### Combining Custom Tools with Structured Output

You can combine custom tools with structured output to gather data before returning a structured response. This requires tool calling mode to be enabled:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

$schema = new ObjectSchema(
    'weather_analysis',
    'Analysis of weather conditions',
    [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withProviderOptions(['use_tool_calling' => true]) // Required for Anthropic
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Access structured output
dump($response->structured);

// Access tool execution details
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
}
```

> [!IMPORTANT]
> When using custom tools with structured output on Anthropic, you must:
> - Set `use_tool_calling: true` in provider options
> - Set `maxSteps` to at least 2

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

### Strict Tool Use

When using the `structured-outputs-2025-11-13` beta feature, you can enable strict validation for tool inputs. This guarantees that tool parameters exactly match your schema through constrained decoding.

To enable strict mode for a tool, use the `strict` provider option:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->withProviderOptions(['strict' => true])
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5-20250929')
    ->withTools([$weatherTool])
    ->withPrompt('What is the weather in San Francisco?')
    ->asText();
```

**Benefits of strict tool use:**
- Functions receive correctly-typed arguments every time
- No need to validate tool inputs
- Eliminates runtime errors from type mismatches
- Production-ready agents that work consistently

### Schema Limitations

When using native structured outputs, certain JSON Schema features are not supported by Anthropic's constrained decoding:

**Not Supported:**
- Recursive schemas
- Numerical constraints (`minimum`, `maximum`, `multipleOf`)
- String constraints (`minLength`, `maxLength`)
- Complex regex patterns (lookahead/lookbehind, backreferences)
- External `$ref` definitions

**Supported:**
- All basic types (object, array, string, integer, number, boolean, null)
- `enum` for simple types (strings, numbers, booleans)
- `anyOf` and `allOf` (with limitations)
- `required` and `additionalProperties: false`
- String formats (`date-time`, `email`, `uri`, `uuid`, etc.)

If you use an unsupported feature, Anthropic will return a 400 error with details. For more information, see [Anthropic's structured outputs documentation](https://docs.anthropic.com/en/api/structured-outputs).

## Limitations
### Messages

Most providers' API include system messages in the messages array with a "system" role. Anthropic does not support the system role, and instead has a "system" property, separate from messages.

Therefore, for Anthropic we:
* Filter all `SystemMessage`s out, omitting them from messages.
* Always submit the prompt defined with `->withSystemPrompt()` at the top of the system prompts array.
* Move all `SystemMessage`s to the system prompts array in the order they were declared.

### Images

Does not support `Image::fromURL`
