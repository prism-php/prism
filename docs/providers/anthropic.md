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

Anthropic's prompt caching feature allows you to drastically reduce latency and your API bill when repeatedly re-using blocks of content within five minutes of each other.

We support Anthropic prompt caching on:

- System Messages (text only)
- User Messages (Text, Image and PDF (pdf only))
- Assistant Messages (text only)
- Tools

The API for enabling prompt caching is the same for all, enabled via the `withProviderMeta()` method. Where a UserMessage contains both text and an image or document, both will be cached.

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        (new SystemMessage('I am a long re-usable system message.'))
            ->withProviderMeta(Provider::Anthropic, ['cacheType' => 'ephemeral']),

        (new UserMessage('I am a long re-usable user message.'))
            ->withProviderMeta(Provider::Anthropic, ['cacheType' => 'ephemeral'])
    ])
    ->withTools([
        Tool::as('cache me')
            ->withProviderMeta(Provider::Anthropic, ['cacheType' => 'ephemeral'])
    ])
    ->asText();
```

If you prefer, you can use the `AnthropicCacheType` Enum like so:

```php
use Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;

(new UserMessage('I am a long re-usable user message.'))->withProviderMeta(Provider::Anthropic, ['cacheType' => AnthropicCacheType::ephemeral])
```
Note that you must use the `withMessages()` method in order to enable prompt caching, rather than `withPrompt()` or `withSystemPrompt()`.

Please ensure you read Anthropic's [prompt caching documentation](https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching), which covers some important information on e.g. minimum cacheable tokens and message order consistency.

## Extended thinking

Claude Sonnet 3.7 supports an optional extended thinking mode, where it will reason before returning its answer. Please ensure your consider [Anthropic's own extended thinking documentation](https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking) before using extended thinking with caching and/or tools, as there are some important limitations and behaviours to be aware of.

### Enabling extended thinking and setting budget
Prism supports thinking mode for text and structured with the same API:

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    // enable thinking
    ->withProviderMeta(Provider::Anthropic, ['thinking' => ['enabled' => true]]) 
    ->asText();
```
By default Prism will set the thinking budget to the value set in config, or where that isn't set, the minimum allowed (1024).

You can overide the config (or its default) using `withProviderMeta`:

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    // Enable thinking and set a budget
    ->withProviderMeta(Provider::Anthropic, [
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
use Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderMeta(Provider::Anthropic, ['thinking' => ['enabled' => true]]) 
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
    ->withProviderMeta(Provider::Anthropic, ['thinking' => ['enabled' => true]])
    ->asText();

$response->steps->first()->additionalContent->thinking;
```

### Extended output mode

Claude Sonnet 3.7 also brings extended output mode which increase the output limit to 128k tokens. 

This feature is currently in beta, so you will need to enable to by adding `output-128k-2025-02-19` to your Anthropic anthropic_beta config (see [Configuration](#configuration) above).

## Documents

Anthropic supports PDF, text and markdown documents. Note that Anthropic uses vision to process PDFs under the hood, and consequently there are some limitations detailed in their [feature documentation](https://docs.anthropic.com/en/docs/build-with-claude/pdf-support).

See the [Documents](/input-modalities/documents.html) on how to get started using them.

Anthropic also supports "custom content documents", separately documented below, which are primarily for use with citations.

### Custom content documents

Custom content documents are primarily for use with citations (see below), if you need citations to reference your own chunking strategy.

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;

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

### Enabling citations

Anthropic require citations to be enabled on all documents in a request. To enable them, using the `withProviderMeta()` method when building your request:

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;

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
    ->withProviderMeta(Provider::Anthropic, ['citations' => true])
    ->asText();
```

### Accessing citations

You can access the chunked output with its citations via the additionalContent property on a response, which returns an array of `Providers\Anthropic\ValueObjects\MessagePartWithCitations`s.

As a rough worked example, let's assume you want to implement footnotes. You'll need to loop through those chunks and (1) re-construct the message with links to the footnotes; and (2) build an array of footnotes to loop through in your frontend.

```php
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Prism\Prism\Providers\Anthropic\ValueObjects\Citation;

$messageChunks = $response->additionalContent['messagePartsWithCitations'];

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $messageChunk  */
foreach ($messageChunks as $messageChunk) {
    $text .= $messageChunk->text;
    
    /** @var Citation $citation */
    foreach ($messageChunk->citations as $citation) {
        $footnotes[] = [
            'id' => $footnoteId,
            'document_title' => $citation->documentTitle,
            'reference_start' => $citation->startIndex,
            'reference_end' => $citation->endIndex
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

While Anthropic models don't have native JSON mode or structured output like some providers, Prism implements a robust workaround for structured output:

- We automatically append instructions to your prompt that guide the model to output valid JSON matching your schema
- If the response isn't valid JSON, Prism will raise a PrismException

## Limitations
### Messages

Most providers' API include system messages in the messages array with a "system" role. Anthropic does not support the system role, and instead has a "system" property, separate from messages.

Therefore, for Anthropic we:
* Filter all `SystemMessage`s out, omitting them from messages.
* Always submit the prompt defined with `->withSystemPrompt()` at the top of the system prompts array.
* Move all `SystemMessage`s to the system prompts array in the order they were declared.

### Images

Does not support `Image::fromURL`
