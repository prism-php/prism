# Prism Server

Prism Server exposes registered Prism configurations through an OpenAI-compatible API for use by chat interfaces and other clients.

## How It Works

Prism Server exposes a subset of the OpenAI Chat Completions API. It provides a model list and text completions backed by application-owned Prism configuration. Client support depends on whether the client can use this request subset.

## Setting Up Prism Server

::: danger Protect the endpoints before enabling them
The default `prism_server.middleware` list is empty. Enabling the server without adding authentication and authorization exposes endpoints that spend your provider credentials. Apply your application's authentication, access policy and rate limits before making these routes reachable. The `api` middleware group alone does not authenticate callers.
:::

### 1. Enable Prism Server

Set `PRISM_SERVER_ENABLED=true` in the server environment and update the existing `prism_server` entry in `config/prism.php`. For an application already configured to authenticate API tokens with Sanctum:

```php
'prism_server' => [
    // The middleware that will be applied to the Prism Server routes.
    'middleware' => ['auth:sanctum', 'throttle:60,1'],
    'enabled' => env('PRISM_SERVER_ENABLED', false),
],
```

### 2. Register Your Prisms

To make your Prism models available through the server, you need to register them. This is typically done in a service provider, such as `AppServiceProvider`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\PrismServer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PrismServer::register(
            'my-custom-model',
            fn () => Prism::text()
                ->using(Provider::Anthropic, 'claude-sonnet-5-5')
                ->withSystemPrompt('You are a helpful assistant.')
        );
    }
}
```

Clients use the registered name `my-custom-model`. Keep credentials and generation controls in the registered factory or server configuration, and authorize which callers may use it.

## Using Prism Server

Once set up, Prism Server exposes two main endpoints:

### Chat Completions

To generate text using your registered Prism models:

```bash
curl -X POST "https://your-app.example/prism/openai/v1/chat/completions" \
     -H "Authorization: Bearer $PRISM_SERVER_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
  "model": "my-custom-model",
  "messages": [
    {"role": "user", "content": "Hello, who are you?"}
  ]
}'
```

### Streaming

Send `"stream": true` in the same JSON body and use `curl --no-buffer` to receive SSE. The endpoint forwards text deltas as `chat.completion.chunk` objects and terminates with `data: [DONE]`. It does not forward Prism tool, approval, citation, thinking, step or final usage events. The streamed model field is `unknown`. Expose `asEventStreamResponse()` from an authenticated application route when your client needs Prism's complete event stream.

### List Available Models

To get a list of all registered Prism models:

```bash
curl "https://your-app.example/prism/openai/v1/models" \
     -H "Authorization: Bearer $PRISM_SERVER_TOKEN"
```

## Integration with Open WebUI

Configure an OpenAI-compatible client with the base URL `https://your-app.example/prism/openai/v1`, its application-issued API token and a registered model name. The curl examples assume that token is stored in the client environment as `PRISM_SERVER_TOKEN`.

## Supported request fields

The controller reads `model`, `messages` and `stream`. Always include a message array. Client fields such as `temperature`, `max_tokens`, `top_p`, `tools`, `tool_choice` and `response_format` do not override the registered builder.

Message roles are limited to `user`, `assistant` and `system`; a `tool` role is refused. User content can carry supported OpenAI-style text/image parts, while assistant and system content are mapped as text. Use Prism directly for client-tool continuation and approvals.

The non-streaming endpoint always serializes `finish_reason` as `stop`; it does not preserve Prism's refusal, truncation or other finish reasons. Use Prism directly when your workflow needs those distinctions.

## Errors

Errors use the OpenAI shape, `{"error":{"message":"...","type":"...","code":...}}`, with a fixed message. An exception's own message is never sent to the caller:

| Failure | Status | `code` |
| --- | --- | --- |
| Unknown model | 404 | `model_not_found` |
| Messages that cannot be read | 422 | `invalid_messages` |
| Anything else, including provider errors | 500 | `null` (`type` is `server_error`) |

For a 500, the exception is reported through your application's exception handler, so it reaches your logs with its full detail. Requests that fail validation keep Laravel's validation response (422).

To decide the response yourself, register a renderer. Return a response to use it, or `null` to keep the default:

```php
use Prism\Prism\Facades\PrismServer;

PrismServer::renderErrorsUsing(function (Throwable $e) {
    return response()->json([
        'error' => ['message' => 'The assistant is unavailable. Try again later.', 'type' => 'server_error'],
    ], 503);
});
```

Whatever your renderer returns is sent as it is, so do not put exception messages in it unless your callers may see them. If the renderer throws, Prism uses the default response and reports both exceptions.

Streaming failures can occur after HTTP headers have been sent and are not converted by that outer error handler. A stream closing without its expected terminator is not successful completion.

## Adding Middleware

You can add middleware to the Prism Server routes by setting the `middleware` option in your `config/prism.php` file:

```php
// For an application configured to authenticate API tokens with Sanctum:
return ['prism_server' => [
    'middleware' => ['auth:sanctum'],
]];
```
