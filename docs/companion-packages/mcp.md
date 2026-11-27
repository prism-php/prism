# Prism MCP

Consume remote MCP tools as Prism tools, with explicit trust declarations, optional definition pins, invocation gates and bounded results.

## Installation

```bash
composer require particle-academy/prism-mcp
php artisan vendor:publish --tag=prism-mcp-config
```

Settings live in `config/prism-mcp.php`. Configure an application-controlled server endpoint and credentials. This client uses Streamable HTTP and speaks MCP revision `2026-07-28`; the server must support that revision. It does not provide stdio transport or an OAuth authorization flow.

## Configure a server

Add an entry under the existing `servers` key:

```php
'servers' => [
    'github' => [
        'url' => env('MCP_GITHUB_URL'),
        'headers' => [
            'Authorization' => 'Bearer '.env('MCP_GITHUB_TOKEN', ''),
        ],
        'trust' => [
            'tools' => ['search_repositories', 'get_file_contents'],
        ],
        'gate' => 'laravel',
    ],
],
```

Define the application's `mcp.call` Gate before using this example. Trust chooses which definitions reach the model; the Gate decides whether the current actor may invoke a trusted tool. The default gate is null, which permits trusted calls. Laravel's default ability is configurable at `gates.laravel.ability` and receives server name, tool name and arguments after the Gate user.

```php
use Prism\Mcp\Facades\PrismMcp;
use Prism\Prism\Facades\Prism;

$tools = PrismMcp::server('github')->tools();

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools($tools)
    ->withMaxSteps(3)
    ->withPrompt('Find repositories relevant to our billing integration.')
    ->asText();
```

Tool names are namespaced by server for the model. Keep server descriptions and results untrusted; admission and framing do not make remote instructions safe to obey.

## Trust and pins

`tools()` refuses an undeclared trust policy before contacting the server. An explicit tool list admits those names. `trust.tools = '*'` or `trustingEveryTool()` admits whatever the server publishes; choose it only when that is your intended policy.

Inspect definitions and obtain digests before pinning:

```bash
php artisan prism-mcp:pins github --tool=search_repositories --json
```

The command contacts the configured server through the raw client without offering tools to a model. Review the definition, then copy its exact digest into `trust.pins[<tool-name>]` or pass the map to `pinning()`. Pins detect a changed reviewed definition; they do not establish that its behavior or output is trustworthy.

## Connection options

The ad-hoc form has the same trust requirement:

```php
use Prism\Mcp\Facades\PrismMcp;

// $mcpUrl is an application-approved endpoint; $mcpToken is server-side.
$connection = PrismMcp::client($mcpUrl, ['name' => 'internal'])
    ->trusting(['lookup_order'])
    ->withToken($mcpToken)
    ->withTimeout(15)
    ->withMaxResultBytes(65536);

$tools = $connection->tools();
```

| Method | Purpose |
|---|---|
| `trusting($names)`, `trustingEveryTool()` | Definition admission policy |
| `pinning($digests)` | Add definition pins |
| `withTimeout($seconds)` | Transport timeout |
| `withHeaders($headers)`, `withToken($token)` | Server-side request credentials |
| `usingGate(ToolGate $gate)` | Invocation authorization |
| `filteringResults(Closure $filter)` | Transform result text; receives text, server and tool |
| `withMaxResultBytes($bytes)` | Refuse oversized results |
| `withoutProvenanceFraming()` | Disable result framing; use only with your own equivalent controls |
| `withoutCache()`, `cachingFor($seconds, $store = null)` | Tool-list caching |
| `config()` | Inspect resolved server configuration |
| `client()` | Raw protocol client; bypasses tool trust, pins, gates and result controls |

Use application-authorized endpoints and credentials. A raw client call is outside the guarded Prism-tool path.

## Results and failures

Results, including error results, are size-checked and framed with source attribution and a per-call delimiter nonce. Over-limit results raise `ResultTooLarge`; they are not silently truncated. Filters can transform text before it reaches the model. Framing remains a prompt-injection mitigation rather than an authorization mechanism.

Exceptions under `Prism\Mcp\Exceptions` include `McpException`, `ServerNotConfigured`, `ServerNotTrusted`, `ToolDefinitionChanged`, `ToolDenied`, `ResultTooLarge`, `MirroredParameterRefused`, `ProtocolFailure`, `TransportFailure`, `ToolCallFailed`, `ServerTimedOut`, `UnsupportedProtocolVersion` and `UnsupportedTransport`. Handle them separately from a model choosing not to call a tool.

Core tool handling can convert invocation exceptions to `ToolError` results instead of propagating them. Configure a redacted failure callback when appropriate and inspect tool failures; see [tool error handling](/core-concepts/tools-function-calling#error-handling).

## Configuration reference

| Key | Default / purpose |
|---|---|
| `client.name`, `client.version` | Self-reported client identification; name uses `PRISM_MCP_CLIENT_NAME` or `APP_NAME` |
| `defaults.timeout` | 30 seconds, via `PRISM_MCP_TIMEOUT` |
| `defaults.cache_ttl` | null honors server TTL; 0 disables caching |
| `defaults.max_result_bytes` | 262144 bytes |
| `defaults.frame_results` | true |
| `defaults.gate` | null, via `PRISM_MCP_GATE` |
| `servers.<name>.*` | Endpoint, headers, trust, pins and per-server overrides |
| `gates.laravel.ability` | `mcp.call` |
| `gates.fms.feature`, `gates.fms.per_server` | `mcp.tools`, true; optional Feature Management System integration |

Custom gates implement `Prism\Mcp\Contracts\ToolGate`. The `fms` gate requires its optional Feature Management System package. To expose your application's own tools as an MCP server, use a server package such as `laravel/mcp`; check protocol compatibility independently. For application-local Prism tools, use `(new Tool)->as(...)`.

See the [package guide](https://github.com/Particle-Academy/prism-mcp) for protocol and custom-gate integration.
