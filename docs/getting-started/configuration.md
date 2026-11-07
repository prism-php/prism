# Configuration

Publish the package configuration, then set credentials for the providers your application uses:

```bash
php artisan vendor:publish --tag=prism-config
```

Settings live in `config/prism.php`. Keep credentials in the server environment and out of browser responses, source control and model-generated options.

## Provider configuration

Each entry under `providers` configures one integration. Environment names are provider-specific:

| Provider | Credentials and endpoint variables |
|---|---|
| OpenAI | `OPENAI_API_KEY`, `OPENAI_URL`, `OPENAI_ORGANIZATION`, `OPENAI_PROJECT`, `OPENAI_API_FORMAT` |
| Anthropic | `ANTHROPIC_API_KEY`, `ANTHROPIC_URL`, `ANTHROPIC_API_VERSION`, `ANTHROPIC_BETA` |
| Azure | `AZURE_AI_API_KEY`, `AZURE_AI_URL`, `AZURE_AI_API_VERSION`, `AZURE_AI_DEPLOYMENT` |
| Vertex AI | `VERTEX_PROJECT_ID`, `VERTEX_REGION`, `VERTEX_ACCESS_TOKEN`, `VERTEX_CREDENTIALS_PATH` |
| Other providers | See the provider guide and the published configuration; do not derive variable names from the provider label |

For example:

```dotenv
OPENAI_API_KEY=your-server-side-key
OPENAI_API_FORMAT=responses
ANTHROPIC_API_KEY=your-server-side-key
```

Use the [provider guides](/getting-started/introduction#providers) for all supported keys, defaults and endpoint formats. Configuration chooses credentials and routing; `withProviderOptions()` chooses request-specific model options.

## Request timeout

`request_timeout` reads `PRISM_REQUEST_TIMEOUT` and defaults to 30 seconds. The shared Laravel HTTP client uses it for both its connection and overall timeout. Override these per request when needed:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withClientOptions(['timeout' => 120, 'connect_timeout' => 10])
    ->withPrompt('Explain database indexing.')
    ->asText();
```

These client options configure the HTTP transport, not the provider's JSON body. Check [Azure streaming](/providers/azure#streaming) before relying on Laravel client settings for that provider's native v1 path. Explicit media fetches have their own behavior; the provider timeout is not a global limit for every operation in your application.

## Retries

`withClientRetry()` accepts the Laravel HTTP client's attempt count or delay array, optional delay, retry predicate and throw flag:

```php
use Illuminate\Http\Client\ConnectionException;
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withClientRetry(
        times: 3,
        sleepMilliseconds: 250,
        when: fn ($error): bool => $error instanceof ConnectionException,
        throw: true,
    )
    ->withPrompt('Explain database indexing.')
    ->asText();
```

The full signature is `withClientRetry(array|int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true)`. A delay array supplies the backoff schedule instead of a fixed delay. Retries can repeat billable requests; choose a predicate and budget appropriate for your application. See [rate limits](/advanced/rate-limits) for provider quota and retry information.

## Per-request provider overrides

The third argument to `using()` merges an application-controlled override with the configured provider entry:

```php
use Prism\Prism\Facades\Prism;

// $tenantApiKey is loaded after authenticating and authorizing the tenant.
$response = Prism::text()
    ->using('openai', 'gpt-4.1', ['api_key' => $tenantApiKey])
    ->withPrompt('Explain quantum computing.')
    ->asText();
```

Alternatively, call `usingProviderConfig($config)` after `using()`; this resolves the provider again from its configured defaults plus that override. It does not merge with earlier per-request overrides. Keep endpoint overrides under application control because requests carry provider credentials.

## Telemetry

Telemetry is disabled by default. The configuration keys and environment variables are:

| Key under `telemetry` | Environment variable | Default |
|---|---|---|
| `enabled` | `PRISM_TELEMETRY_ENABLED` | false |
| `capture_content` | `PRISM_TELEMETRY_CAPTURE_CONTENT` | false |
| `capture_media` | `PRISM_TELEMETRY_CAPTURE_MEDIA` | false |
| `content_max_length` | `PRISM_TELEMETRY_CONTENT_MAX_LENGTH` | 65536 |
| `content_max_items` | `PRISM_TELEMETRY_CONTENT_MAX_ITEMS` | 256 |

Enable content capture only for a trusted sink. Prompts, completions, tool arguments and media can contain private information. The length/item limits bound opt-in content reconstruction; they do not make captured content anonymous. See [telemetry](/advanced/telemetry) for events, null fields, capture controls and operations that do not emit events.

## Prism Server

`prism_server.enabled` reads `PRISM_SERVER_ENABLED` and defaults to false. `prism_server.middleware` defaults to an empty array.

::: danger Authenticate before enabling
Enabling Prism Server with the default middleware exposes endpoints that can spend your provider credentials. Configure authentication, authorization and rate limiting before making the routes reachable. See [Prism Server](/core-concepts/prism-server) for an authenticated setup.
:::
