# Custom Providers

Extend `Prism\Prism\Providers\Provider` and register it with the Prism manager. Override the capabilities your service implements; inherited capability methods raise an unsupported-action `PrismException`.

## Building your provider

This complete local example returns the prompt as text. It demonstrates the required response shape without making an HTTP request. It supports a single plain-text prompt; it refuses other inputs and controls rather than silently ignoring them.

```php
namespace App\Prism\Providers;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class LocalTextProvider extends Provider
{
    public function text(TextRequest $request): TextResponse
    {
        $messages = $request->messages();
        if ($request->prompt() === null || count($messages) !== 1
            || ! $messages[0] instanceof UserMessage || count($messages[0]->additionalContent) !== 1
            || ! $messages[0]->additionalContent[0] instanceof Text
            || $request->systemPrompts() !== [] || $request->tools() !== []
            || $request->providerTools() !== [] || $request->providerOptions() !== []
            || $request->toolChoice() !== null || $request->maxTokens() !== null
            || $request->temperature() !== null || $request->topP() !== null
            || $request->topK() !== null || $request->reasoningEnabled() !== null) {
            throw new PrismException('LocalTextProvider accepts one plain-text prompt only.');
        }

        $text = $request->prompt();

        return new TextResponse(
            steps: collect([]),
            text: $text,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('local-response', $request->model()),
            messages: collect([...$messages, new AssistantMessage($text)]),
            additionalContent: [],
            raw: null,
        );
    }
}
```

A network-backed implementation must map supported messages and controls, validate the provider response, map usage and finish reasons, and preserve provider state needed for continuation. Implement tools or streaming only when those paths honor the same input and authorization requirements. Use application-controlled credentials and endpoints; do not allow model output to choose them.

## Registration process

Register a lower-case key in a Laravel service provider:

```php
namespace App\Providers;

use App\Prism\Providers\LocalTextProvider;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(PrismManager::class)->extend(
            'local-text',
            fn ($app, array $config) => new LocalTextProvider,
        );
    }
}
```

The callback receives the Laravel application and the provider configuration. Add network credentials under `providers.<your-key>` in the existing `config/prism.php` when your implementation needs them.

`resolve()` lowercases provider names, while `extend()` stores the key exactly as supplied. Register lower-case keys so the custom creator can be found.

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('local-text', 'local-echo')
    ->withPrompt('Hello, custom provider!')
    ->asText();

echo $response->text;
```

## Capability methods

The base class exposes these 21 capability methods. Use its parameter and return types when overriding them:

| Capability | Methods |
|---|---|
| Text and structured output | `text()`, `structured()`, `stream()` |
| Embeddings, images and moderation | `embeddings()`, `images()`, `moderation()` |
| Audio | `textToSpeech()`, `speechToText()`, `speechToTextProviderId()`, `speechToTextAsync()` |
| Fill-in-the-middle | `fim()` |
| Batch | `batch()`, `retrieveBatch()`, `listBatches()`, `getBatchResults()`, `cancelBatch()` |
| Files | `uploadFile()`, `listFiles()`, `getFileMetadata()`, `deleteFile()`, `downloadFile()` |

For example, `stream(TextRequest $request): Generator` yields `StreamEvent` objects, and `downloadFile(DownloadFileRequest $request): string` returns bytes. A method being declared does not mean it is implemented by an existing built-in provider.

## Request payload projection

The base provider returns `null` from `projectPayload()`. You can override it
with a side-effect-free mapping consistent with your sender. See
[Request payload projection](./payload-projection) for the supported modes,
approval-resolution behavior, and data handling requirements.

## Custom error handling

The separate `handleRequestException(string $model, RequestException $e): never` hook maps HTTP failures. Its default implementation maps 413 to `PrismRequestTooLargeException`, 429 to `PrismRateLimitedException`, 529 to `PrismProviderOverloadedException`, and other statuses to `PrismException`.

Add provider-specific mapping in your subclass when needed:

```php
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Provider;

class RateAwareProvider extends Provider
{
    public function handleRequestException(string $model, RequestException $e): never
    {
        if ($e->response->status() === 429) {
            $retry = $e->response->header('retry-after');
            throw PrismRateLimitedException::make(
                rateLimits: [],
                retryAfter: ctype_digit($retry) ? (int) $retry : null,
            );
        }

        parent::handleRequestException($model, $e);
    }
}
```

`processRateLimits()` is not a base-class method. Parse your service's headers yourself or use a compatible provider-specific trait. Exception messages and response bodies can contain provider content; expose them only according to your application's data policy. Test the implementation with `Http::fake()` and `Http::preventStrayRequests()` when it uses Laravel's HTTP client.
