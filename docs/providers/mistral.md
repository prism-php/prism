# Mistral
## Configuration

```php
'mistral' => [
    'api_key' => env('MISTRAL_API_KEY', ''),
    'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
],
```
## Provider-specific options

### Embedding encoding

Embeddings accept `withProviderOptions(['encoding_format' => 'base64'])`.
Prism decodes the result as little-endian float32 into numeric vectors while
preserving the provider payload in `raw`. Omitting this option preserves the
provider's default float format.

Malformed or noncanonical base64, empty encodings, incomplete float32 values
and non-finite numbers throw `PrismException` without including the payload in
the exception message.

### Reasoning Effort

Use `reasoning_effort` with a model that supports it, such as `mistral-small-latest` or `mistral-medium-3-5`.

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('mistral', 'mistral-small-latest')
    ->withPrompt('Solve this logic problem step by step.')
    ->withProviderOptions([ // [!code focus]
        'reasoning_effort' => 'high', // [!code focus]
    ]) // [!code focus]
    ->asText();

// Access the final answer
echo $response->text;

// Access the reasoning process
echo $response->additionalContent['thinking'] ?? '';
```

More details in the official Mistral documentation:
https://docs.mistral.ai/capabilities/reasoning/adjustable

## Reasoning Models
### Using Reasoning Models

Request a thinking trace with a compatible model and `reasoning_effort: high`. Prism exposes thinking separately when returned:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('mistral', 'mistral-medium-3-5')
    ->withProviderOptions(['reasoning_effort' => 'high'])
    ->withPrompt('What is the capital of France?')
    ->asText();

// Access the final answer
echo $response->text;
// "The capital of France is Paris."

// Access the reasoning process
echo $response->additionalContent['thinking'] ?? '';
// "Okay, the user asked about the capital of France. I know that the capital of France is Paris..."
```

### Accessing Thinking Content

You can access the thinking content via the `additionalContent` property on either the Response or the relevant Step.

On the Response (easiest when not using tools):

```php
$response = Prism::text()
    ->using('mistral', 'mistral-medium-3-5')
    ->withProviderOptions(['reasoning_effort' => 'high'])
    ->withPrompt('What is the meaning of life in popular fiction?')
    ->asText();

// Get the reasoning process
$thinking = $response->additionalContent['thinking'] ?? '';

// Get the final answer
$answer = $response->text;
```

On the Step (necessary when using tools, as reasoning happens before tool calls):

```php
$tools = [
    Tool::as('search')
        ->for('Search for current information')
        ->withStringParameter('query', 'The search query')
        ->using(fn (string $query): string => 'The Tigers game is at 3pm'),
];

$response = Prism::text()
    ->using('mistral', 'mistral-medium-3-5')
    ->withProviderOptions(['reasoning_effort' => 'high'])
    ->withTools($tools)
    ->withMaxSteps(3)
    ->withPrompt('What time is the Tigers game today?')
    ->asText();

// Access thinking from the first step
$thinking = $response->steps->first()?->additionalContent['thinking'] ?? '';
```

### Understanding the Response Structure

Reasoning models return content in a structured format with two types of blocks:

1. **Thinking blocks** - The model's internal reasoning process (stored in `additionalContent['thinking']`)
2. **Text blocks** - The final self-contained answer (stored in `text`)

Prism exposes thinking and text content separately.

## Streaming

Mistral supports streaming responses in real-time. All standard streaming methods are supported:

```php
return Prism::text()
    ->using('mistral', 'mistral-large-latest')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Token Usage

Mistral's `prompt_tokens` includes the tokens it served from the cache, so Prism takes them out to keep the input buckets apart (see [Token Usage](/core-concepts/text-generation#token-usage)). Text, structured and streamed responses all report usage like this:

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `prompt_tokens` minus `prompt_tokens_details.cached_tokens` |
| `cacheReadInputTokens` | `prompt_tokens_details.cached_tokens`, `null` when Mistral leaves it out |
| `cacheWriteInputTokens` | Always `null`, Mistral doesn't report cache writes |
| `completionTokens` | `completion_tokens`, reasoning included |
| `thoughtTokens` | `completion_tokens_details.reasoning_tokens`, already part of `completionTokens` |

Mistral either reports `0` or leaves `cached_tokens` out when nothing came from the cache, so a `null` cache count usually means there wasn't a hit. Prism doesn't read the older `num_cached_tokens` field: Mistral doesn't document it, and we've recorded a response where it's larger than `prompt_tokens`, so it can't simply be the cached part of the prompt.

In a stream, Mistral sends usage on the last chunk of each response. The `StreamEndEvent` adds up every step of a tool loop, and each `StepFinishEvent` carries its own step's usage.

## Structured output and tools

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->using('mistral', 'mistral-small-latest')
    ->withSchema(new ObjectSchema(
        'summary', 'A summary', [new StringSchema('text', 'Summary text')],
        requiredFields: ['text'],
    ))
    ->withPrompt('Summarize the purpose of a database index.')
    ->asStructured();
```

Mistral structured output uses strict `json_schema` format. When tools are configured, the tool phase runs before the final schema-constrained generation. Use `withTools()`, `withMaxSteps()` and `withToolChoice()` as described in [Tools & Function Calling](/core-concepts/tools-function-calling).

## Embeddings

```php
use Prism\Prism\Facades\Prism;

$response = Prism::embeddings()
    ->using('mistral', 'mistral-embed')
    ->fromArray(['First document', 'Second document'])
    ->asEmbeddings();

$vector = $response->embeddings[0]->embedding;
```

Read vectors from `embeddings`, token counts from `usage`, and provider metadata from `meta`. Set `encoding_format` through provider options when required by your model.

## Fill-in-the-middle

Use a FIM-capable model to generate the missing text between a prefix and suffix:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::fim()
    ->using('mistral', 'codestral-latest')
    ->withPrompt('function add($a, $b) {')
    ->withSuffix('}')
    ->withMaxTokens(100)
    ->withTemperature(0.2)
    ->withTopP(0.9)
    ->withStop(["\n\n"])
    ->asText();

echo $response->text;
```

FIM uses `withTemperature()` and `withTopP()`. Its response exposes `text`, `finishReason`, `usage` and `meta`. This handler sends only the mapped FIM fields; arbitrary provider options are not forwarded. See [Mistral's FIM API](https://docs.mistral.ai/api/endpoint/fim) for compatible model IDs.

## Audio Processing

Use Mistral's Voxtral models for speech-to-text requests.

### Speech-to-Text

Select a Voxtral transcription model and supply an audio file:

#### Basic STT Usage

```php
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromLocalPath('/path/to/recording.mp3');

$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

#### Model Selection Guide

Choose the right Voxtral model for your use case:

```php
// Select a transcription model enabled for your account.
$response = Prism::audio()
    ->using('mistral', 'voxtral-small-latest')
    ->withInput($audioFile)
    ->asText();

// Select a compatible transcription model.
$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($audioFile)
    ->asText();

// For optimized transcription-only service
$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($audioFile)
    ->asText();
```

#### Language Detection and Specification

Voxtral automatically detects languages or you can specify them for better accuracy:

```php
$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'en',           // ISO-639-1 code (optional)
        'temperature' => 0.0,         // Lower for more deterministic results
    ])
    ->asText();

// Multilingual support - single model handles multiple languages
$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($multilingualAudioFile)
    ->withProviderOptions([
        // Auto-detection works well for mixed-language content
        'temperature' => 0.1,
    ])
    ->asText();
```

#### Timestamps and Segmentation

Get detailed timing information with your transcriptions:

Set `diarize => true` in provider options to request speaker diarization on a compatible transcription model. Other forwarded transcription options are `language`, `prompt`, `response_format`, `temperature` and `timestamp_granularities`. Read provider-specific fields from `additionalContent`.

```php
$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($audioFile)
    ->withProviderOptions([
        'timestamp_granularities' => ['segment'], // Available: word, segment
        'response_format' => 'json',
    ])
    ->asText();

// Access segment information with timestamps
$segments = $response->additionalContent['segments'] ?? [];
foreach ($segments as $segment) {
    echo "Text: " . $segment['text'] . "\n";
    echo "Start: " . $segment['start'] . "s\n";
    echo "End: " . $segment['end'] . "s\n";
}
```

#### Context and Prompts

Improve transcription accuracy with contextual information:

```php
$response = Prism::audio()
    ->using('mistral', 'voxtral-mini-latest')
    ->withInput($audioFile)
    ->withProviderOptions([
        'prompt' => 'This is a medical consultation discussing patient symptoms and treatment options.',
        'language' => 'en',
        'temperature' => 0.0,         // Deterministic for medical content
    ])
    ->asText();
```

#### Long-form Audio Processing

Use a transcription model whose file and duration limits cover the input:

```php
// Use a file within your selected transcription model's limits.
$longAudioFile = Audio::fromLocalPath('/path/to/long_meeting.wav');

$response = Prism::audio()
    ->using('mistral', 'voxtral-small-latest')
    ->withInput($longAudioFile)
    ->withProviderOptions([
        'timestamp_granularities' => ['segment'],
        'language' => 'en',
    ])
    ->asText();

echo "Full transcription: " . $response->text;

// Access usage information
if ($response->usage) {
    echo "Input tokens, audio included: " . $response->usage->promptTokens . "\n";
    echo "Output tokens: " . $response->usage->completionTokens . "\n";
}
```

> [!NOTE]
> Transcription responses can report a `total_tokens` that's larger than `prompt_tokens` plus `completion_tokens`, for example 4 + 1072 against a total of 5201. Prism treats the gap as audio input and sets `promptTokens` to the total minus the completion (4129 here). Mistral doesn't document this, and its own examples don't all agree, so treat that number as an estimate. When the total adds up, `promptTokens` is `prompt_tokens` as reported. The audio length in seconds is in `$response->additionalContent['usage']['prompt_audio_seconds']`.

#### Creating Audio Objects

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From local file path
$audio = Audio::fromLocalPath('/path/to/audio.mp3');

// From remote URL
$audio = Audio::fromUrl('https://example.com/recording.wav')->fetchUrlContent(); // a URL you trust

// From base64 encoded data
$audio = Audio::fromBase64($base64AudioData, 'audio/mpeg');

// From binary content
$audioContent = file_get_contents('/path/to/audio.wav');
$audio = Audio::fromRawContent($audioContent, 'audio/wav');
```

## Documents
The text generation part of the exposed Facade only allows documents to be passed in through via URL.
See the [documents](./../input-modalities/documents.md) on how to do that.

## OCR
Mistral provides an OCR endpoint which can be used to extract text from documents.
This OCR endpoint can be used like this:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\Providers\Mistral\Mistral;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRResponse;
use Prism\Prism\ValueObjects\Media\Document;

/** @var Mistral $provider */
$provider = Prism::provider(\Prism\Prism\Enums\Provider::Mistral);

/** @var OCRResponse $ocrResponse */
$ocrResponse = $provider->ocr(
    'mistral-ocr-latest',
    Document::fromUrl('https://prismphp.com/storage/prism-text-generation.pdf')
);

/**
* Just need the full text of all the pages combined? Use the toText() method.
 */
$text = $ocrResponse->toText();
```

Both OCR and document parts in text requests require `Document::fromUrl()`. Local paths, base64 and raw document bytes are refused; host a document at a URL accessible to Mistral or use another provider. Check the [model catalog](https://docs.mistral.ai/models) for available chat, embedding, transcription, FIM and OCR models.
::: tip
The OCR endpoint response time can vary depending on the size of the document. We recommend doing this in the background like a queue with a longer timeout.
:::

