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

Credentials are scoped to their configured endpoint. When an override sets a `url` that differs from the configured one, the configured `api_key`, `access_token`, `credentials_path`, `organization` and `project` are not carried over; pass the key for that endpoint in the same override:

```php
$response = Prism::text()
    ->using('openai', 'gpt-4.1', [
        'url' => 'https://gateway.example.com/v1',
        'api_key' => $gatewayApiKey,
    ])
    ->withPrompt('Explain quantum computing.')
    ->asText();
```

A trailing slash, or a difference in the case of the scheme or host, is the same endpoint. A different scheme, host, port, path or query is not. A Vertex `region` must contain only lowercase letters, digits and hyphens. A `url`, configured or per call, that contains whitespace or a control character anywhere, including at either end, is refused with `InvalidArgumentException` before any request is made; it is not trimmed.

### Per-call urls may not choose a private address

A `url` in `config/prism.php` may point anywhere, including a model server on `localhost` or the local network. A `url` passed for one call may not. Before any request is made, a per-call `url` is refused with `PrismUrlRefused` (code `private_address_refused`) when its host is:

- a loopback, private (RFC 1918), link-local, shared (CGNAT), unique-local IPv6 or unspecified address written as a literal, including the decimal (`2130706433`), octal (`0177.0.0.1`), hex (`0x7f000001`), shortened (`127.1`), IPv6 and IPv4-mapped IPv6 (`[::ffff:127.0.0.1]`) forms, and IPv6 forms that carry a private IPv4 address (IPv4-translated `[::ffff:0:127.0.0.1]`, NAT64, 6to4 and ISATAP). An IPv6 address written with a dotted IPv4 tail (`[2606:4700::8.8.8.8]`) is judged like its hex spelling;
- a cloud metadata address such as `169.254.169.254` or `100.100.100.200`;
- `localhost`, a `*.localhost` name, a loopback name from a stock hosts file (`localhost.localdomain`, `localhost4`, `localhost6`, `ip6-localhost`, `ipv6-localhost` and similar), or a documented metadata name (`metadata.google.internal`, `metadata`, `instance-data`);
- a host that holds anything other than letters, digits, `-` and `.` (or an IPv6 address in brackets). A percent escape is refused, not decoded, and so is a backslash before the path. Invisible and formatting characters (such as zero-width joiners and soft hyphens) and the characters `ß`, `ẞ` and `ς`, which IDNA converters handle differently, are refused anywhere before the path.

Trailing dots are ignored, so `localhost.` and `127.0.0.1.` are refused like `localhost` and `127.0.0.1`.

**A per-call host must be ASCII. Give an international name in its punycode form**: `https://xn--bcher-kva.example/v1`, not `https://bücher.example/v1`. IDNA converters do not agree on which name some characters spell, so the check does not convert one. Any non-ASCII per-call host is refused with code `non_ascii_host_use_punycode`, whatever the name, before any request. The punycode form is sent unchanged and is checked like any other name. A configured `url` is not affected.

A per-call `url` that is not an absolute `http` or `https` URL is refused with code `scheme_not_allowed`. No message contains the url. A per-call `url` that names the configured endpoint is not a new address and is accepted.

The check reads the url only. **It does not resolve DNS.** A public hostname that resolves to a private address, including wildcard-DNS names such as `127.0.0.1.nip.io`, is not refused. The check stops mistakes and literal addresses. It does not stop someone who can choose the hostname. If a per-call url can come from a user, do not rely on this check alone: allow only known hosts, or restrict outbound traffic at the network level.

To allow private per-call urls for one provider, set its `allow_private_per_call_url` to `true` in the configuration. The shipped config reads it from `<PROVIDER>_ALLOW_PRIVATE_PER_CALL_URL` (for example `OPENAI_ALLOW_PRIVATE_PER_CALL_URL=true`) and it is off by default. Only `true` turns it on, and the same key passed in a per-call override is ignored.

The setting allows private addresses only. With it on, a per-call url must still be an absolute `http` or `https` url, and its host is still refused if it holds a percent escape, a backslash, an invisible character or a non-ASCII character (give an international name in its punycode `xn--` form).

```php
// config/prism.php
'providers' => [
    'openai' => [
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        'allow_private_per_call_url' => env('OPENAI_ALLOW_PRIVATE_PER_CALL_URL', false),
        // ...
    ],
],
```

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
