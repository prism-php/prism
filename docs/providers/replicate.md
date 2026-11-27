# Replicate

Replicate is a cloud platform that makes it easy to run machine learning models at scale. Unlike traditional LLM APIs, Replicate uses an **asynchronous prediction-based architecture** where you submit a request and poll for results.

## Configuration

```php
'replicate' => [
    'api_key' => env('REPLICATE_API_KEY', ''),
    'url' => env('REPLICATE_URL', 'https://api.replicate.com/v1'),
    'use_sync_mode' => env('REPLICATE_USE_SYNC_MODE', true), // Use Prefer: wait header
    'polling_interval' => (int) env('REPLICATE_POLLING_INTERVAL', 1000), // milliseconds
    'max_wait_time' => (int) env('REPLICATE_MAX_WAIT_TIME', 60), // seconds
    'download_hosts' => ['replicate.delivery'],
]
```

### Configuration Options

- **`api_key`**: Your Replicate API token (get one at [replicate.com/account](https://replicate.com/account))
- **`url`**: Base API URL (default: `https://api.replicate.com/v1`)
- **`use_sync_mode`**: Enable sync mode with `Prefer: wait` header (default: `true`) - reduces latency
- **`polling_interval`**: Time between prediction status checks in milliseconds (default: 1000ms) - used when sync mode times out
- **`max_wait_time`**: Maximum time to wait for prediction completion in seconds (default: 60s)
- **`download_hosts`**: Hosts allowed for generated audio and image downloads. An entry also allows its subdomains. Keep this list limited to trusted media hosts. This setting is read from `config/prism.php`, rather than from the third argument to `using()`.

Polling settings must be integers; cast values read from environment variables as shown above.

## Output downloads

Generated audio and image downloads use a separate HTTP client without API
credentials or per-request client options. Set
`prism.providers.replicate.download_hosts` to the hostnames you trust; the default
is `['replicate.delivery']` and includes their subdomains. An empty list disables
media downloads. URLs must use HTTPS on port 443 without embedded credentials.
Redirects are refused. Downloads have a 30-second timeout, a 10-second connection
timeout and a 25 MiB limit, enforced while reading the response stream.

A refused or failed audio download throws `PrismException`. Image generation
keeps the output URL with `base64` set to `null` when downloading fails.

SSE connections accept only the exact HTTPS hosts `stream.replicate.com` and
`streaming.api.replicate.com` on port 443, without embedded credentials or
redirects. The modern `stream.replicate.com` endpoint uses the separate
credential-free client; only the legacy `streaming.api.replicate.com` endpoint
receives API authentication. `download_hosts` controls media downloads, not SSE
connections. Other stream hosts, including custom gateways, are refused.

The media allowlist trusts the named hosts and their DNS configuration; it does
not apply the public-address guard from
[`fetchPublicUrlContent()`](/input-modalities/images#fetching-remote-media).

## How Replicate Works

Replicate's API differs from most LLM providers with an asynchronous prediction-based architecture. Prism provides two modes:

### Sync Mode (Default - Recommended)
Uses the `Prefer: wait` header to make Replicate wait for the prediction to complete before responding:

1. **Submit prediction with `Prefer: wait`** → Replicate waits up to 60 seconds for completion
2. **Immediate response** → Get results directly if prediction completes within timeout
3. **Automatic fallback** → Falls back to polling if prediction takes longer than timeout

**Benefits**: Lower latency, fewer API calls, faster responses for quick predictions.

### Async Mode (Polling)
Traditional polling approach:

1. **Submit a prediction** → Get a prediction ID
2. **Poll for completion** → Check prediction status until `succeeded` or `failed`
3. **Retrieve output** → Extract results from the completed prediction

**When to use**: Disable sync mode (`use_sync_mode: false`) for very long-running predictions (>60s) to avoid timeouts.

Both modes return through Prism's synchronous request API.

## Supported Features

### ✅ Text Generation

Generate text using large language models like Meta Llama 3.1.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Replicate, 'meta/meta-llama-3-8b-instruct')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

echo $response->text;
```

**Popular text models:**
- `meta/meta-llama-3.1-405b-instruct` - Meta's flagship LLM
- `meta/meta-llama-3-70b-instruct` - Balanced performance/cost
- `meta/meta-llama-3-8b-instruct` - Fast, efficient model

### ✅ Structured Output

Extract structured data using JSON mode.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
  name: "book_review",
  description: "A structure movie review",
  properties: [
    new StringSchema("title", "Book title"),
    new StringSchema("author", "Author name"),
    new NumberSchema("rating", "Rating from 1-5"),
    new StringSchema("summary", "Brief review summary")
  ],
  requiredFields: ["title", "author", "rating", "summary"]
);

$response = Prism::structured()
    ->using(Provider::Replicate, 'meta/meta-llama-3-8b-instruct')
    ->withPrompt('Review "1984" by George Orwell')
    ->withSchema($schema)
    ->asStructured();

echo $response->structured['title']; // "1984"
echo $response->structured['rating']; // 5
```

**How it works:** Prism injects the JSON schema into the prompt and instructs the model to return valid JSON matching the schema.

### ✅ Streaming

Stream text generation token-by-token for real-time UX using Server-Sent Events (SSE).

```php
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Facades\Prism;

$stream = Prism::text()
    ->using('replicate', 'meta/meta-llama-3-8b-instruct')
    ->withPrompt('Write a short story about a robot')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta; // Prints tokens as they arrive in real-time
    }
}
```

**How it works:** 
- Prism connects to Replicate's SSE streaming endpoint (`urls.stream`) for true real-time token delivery
- Tokens arrive progressively as the model generates them (no waiting for completion)
- Full event lifecycle support: StreamStart → TextStart → TextDelta(s) → TextComplete → StreamEnd
- Automatic fallback to simulated streaming if SSE is unavailable

### ✅ Image Generation

Generate images with a supported model.

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('replicate', 'black-forest-labs/flux-schnell')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
echo $image->url;
```

Generated images follow the [output download policy](#output-downloads). If a download is refused or fails, the image retains its URL and has no base64 content.

**Popular image models:**
- `bytedance/seedream-4` - Fast, high-quality generation (1-4 steps)
- `black-forest-labs/flux-dev` - Development model with more control
- `stability-ai/sdxl` - Stable Diffusion XL

**Provider-specific options:**

```php
$response = Prism::image()
  ->using("replicate", "bytedance/seedream-4")
  ->withPrompt("A beautiful sunset over mountains")
  ->withProviderOptions([
    "size" => "2K",
    "width" => 2048,
    "height" => 2048,
    "aspect_ratio" => "4:3"
  ])
  ->generate();
```

### ✅ Text-to-Speech (TTS)

Convert text to natural-sounding speech.

The returned audio URL follows the same [output download policy](#output-downloads)
as generated images. A refused or failed download throws `PrismException`.

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('replicate', 'jaaari/kokoro-82m:f559560eb822dc509045f3921a1921234918b91739db4bf3daab2169b71c7a13')
    ->withInput('Hello! Welcome to Replicate text-to-speech.')
    ->withVoice('af_bella')
    ->withProviderOptions(['voice' => 'af_bella'])
    ->asAudio();

$audio = $response->audio;
if ($audio->hasBase64()) {
  file_put_contents("output.mp3", $audio->rawContent());
  echo "Audio saved as: output.mp3";
}
```

**Available voices for Kokoro-82m:**
- `af_bella` 
- `af_nicole`
- `am_fenrir` 
-  `am_puck`

Set `withVoice()` to initialize the audio request, and also pass the model's `voice` input through provider options. Replicate sends provider options as prediction inputs; it does not map the generic voice field.

**Provider-specific options:**

```php
$response = Prism::audio()
  ->using(
    "replicate",
    "jaaari/kokoro-82m:f559560eb822dc509045f3921a1921234918b91739db4bf3daab2169b71c7a13"
  )
  ->withInput("Hello! Welcome to Replicate text-to-speech.")
  ->withVoice("af_jessica")
  ->withProviderOptions([
    "voice" => "af_jessica",
    "speed" => 2 // Speech speed multiplier
  ])
  ->asAudio();
```

### ✅ Speech-to-Text (STT)

Transcribe audio files to text using Whisper.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromLocalPath('path/to/audio.mp3');

$response = Prism::audio()
    ->using('replicate', 'vaibhavs10/incredibly-fast-whisper:3ab86df6c8f54c11309d4d1f930ac292bad43ace52d10c80d87eb258b3c9f79c')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

**Supported formats:** WAV, MP3, FLAC, OGG, M4A

**Provider-specific options:**

```php
$response = Prism::audio()
    ->using('replicate', 'vaibhavs10/incredibly-fast-whisper:3ab86df6c8f54c11309d4d1f930ac292bad43ace52d10c80d87eb258b3c9f79c')
    ->withInput($audioFile)
    ->withProviderOptions([
        'task' => 'translate',      // 'transcribe' or 'translate' (to English)
        'language' => 'english',           // Optional: specify source language
        'timestamp' => 'chunk',       // chunk, word, or false
        'batch_size' => 64,           // Batch size for processing
    ])
    ->asText();
```

### ✅ Embeddings

Generate vector embeddings for semantic search and similarity.

```php
use Prism\Prism\Facades\Prism;

// Single input
$response = Prism::embeddings()
  ->using(
    "replicate",
    "mark3labs/embeddings-gte-base:d619cff29338b9a37c3d06605042e1ff0594a8c3eff0175fd6967f5643fc4d47"
  )
  ->fromInput("The quick brown fox jumps over the lazy dog")
  ->asEmbeddings();

$embeddings = $response->embeddings[0]->embedding;

// Check token usage
echo $response->usage->tokens;

// Multiple inputs
$response = Prism::embeddings()
    ->using(
        "replicate",
        "mark3labs/embeddings-gte-base:d619cff29338b9a37c3d06605042e1ff0594a8c3eff0175fd6967f5643fc4d47"
    )
    ->fromArray([
        'Document 1 text',
        'Document 2 text',
        'Document 3 text',
    ])
    ->asEmbeddings();

foreach ($response->embeddings as $embedding) {
    // Process each 768-dimensional vector
}
```

**Embeddings model:**
- `mark3labs/embeddings-gte-base` - 768-dimensional embeddings

## Prediction inputs and tools

Provider options are merged into the prediction's `input` object; each model defines its input names and accepted values. They can override mapped input fields, so keep option construction under your application's control. The text handler maps the prompt, system prompt and sampling settings; the structured handler adds schema instructions and defaults `max_tokens` to 4096 unless provider options override it.

Custom function tools are unsupported. Tools supplied through `withTools()` are not sent or executed; this handler does not create a tool loop or throw for their presence. Use a provider with custom-tool support when the model must request application functions.

## Model Versioning

Prism sends the model string unchanged in the prediction's `version` field. It does not resolve friendly names to version hashes. Supply the model identifier or version accepted by your Replicate endpoint; use an explicit version when you need a fixed model revision.

## Async Predictions & Polling

Prism handles Replicate's async architecture transparently:

```php
// This looks synchronous but Prism polls internally
$response = Prism::text()
    ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
    ->withPrompt('Generate text')
    ->asText();

// Prism automatically:
// 1. Creates a prediction
// 2. Polls every 1 second (configurable via polling_interval)
// 3. Returns when prediction succeeds (or times out after max_wait_time)
```

### Custom Polling Configuration

```php
use Prism\Prism\Facades\Prism;

$request = Prism::text()
    ->using('replicate', 'meta/meta-llama-3.1-405b-instruct', [
        'polling_interval' => 500,
        'max_wait_time' => 120,
    ])
    ->withPrompt('Generate text');
```

## Error Handling

Replicate-specific exceptions:

```php
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;

try {
    $response = Prism::text()
        ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
        ->withPrompt('Generate text')
        ->asText();
} catch (PrismRateLimitedException $e) {
    // HTTP 429: Rate limit exceeded
    // Wait and retry with exponential backoff
} catch (PrismProviderOverloadedException $e) {
    // HTTP 529: Replicate's infrastructure is overloaded
    // Retry with longer delay
} catch (PrismRequestTooLargeException $e) {
    // HTTP 413: Request payload too large
    // Reduce input size
}
```

## Performance Optimization

### Sync Mode vs Async Mode

By default, Prism uses **sync mode** (`Prefer: wait` header) for optimal performance:

```php
// Sync mode (default) - Recommended for most use cases
'use_sync_mode' => true,  // Uses Prefer: wait header

// Benefits:
// ✅ Lower latency (no polling delay)
// ✅ Fewer API calls (single request)
// ✅ Faster for quick predictions (<60s)
// ✅ Automatic fallback to polling if needed
```

Disable sync mode for very long predictions:

```php
// Async mode - For predictions that take >60 seconds
'use_sync_mode' => false,  // Traditional polling

// When to use:
// • Very large image generations
// • Complex multi-step processes
// • Known slow models
```

### Custom Sync Mode

You can also configure sync mode per provider instance:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('replicate', 'meta/meta-llama-3.1-405b-instruct', [
        'use_sync_mode' => true,
        'max_wait_time' => 60,
    ])
    ->withPrompt('Generate text')
    ->asText();
```

## Cost Optimization Tips

1. **Use smaller models when possible**: `meta-llama-3.1-8b-instruct` is much cheaper than `405b-instruct`
2. **Optimize image generation**: FLUX Schnell (1-4 steps) is faster and cheaper than FLUX Dev
3. **Batch embeddings**: Process multiple texts in one request
4. **Monitor polling**: Reduce `polling_interval` for faster results but more API calls

## Rate Limits

Replicate's rate limits vary by account tier:
- **Free tier**: Limited predictions per month
- **Pro/Team**: Higher limits based on subscription

Prism automatically handles 429 responses with `PrismRateLimitedException`.

## Resources

- [Replicate Documentation](https://replicate.com/docs)
- [Replicate API Reference](https://replicate.com/docs/reference/http)
- [Replicate Models](https://replicate.com/explore)
- [Get API Token](https://replicate.com/account)
