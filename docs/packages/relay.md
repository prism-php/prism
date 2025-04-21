# Relay

A seamless integration between Prism and Model Context Protocol (MCP) servers that empowers your AI applications with powerful, external tool capabilities.

## Installation

You can install the package via Composer:

```bash
composer require prism-php/relay
```

After installation, publish the configuration file:

```bash
php artisan vendor:publish --tag="relay-config"
```

## Configuration

The published config file (`config/relay.php`) is where you'll define your MCP server connections.

### Configuring Servers

You must define each MCP server explicitly in the config file. Each server needs a unique name and the appropriate configuration parameters:

```php
return [
    'servers' => [
        'puppeteer' => [
            'command' => ['npx', '-y', '@modelcontextprotocol/server-puppeteer'],
            'timeout' => 30,
            'env' => [],
            'transport' => \Prism\Relay\Enums\Transport::Stdio,
        ],
        'github' => [
            'url' => env('RELAY_GITHUB_SERVER_URL', 'http://localhost:8001/api'),
            'timeout' => 30,
            'transport' => \Prism\Relay\Enums\Transport::Http,
        ],
    ],
    'cache_duration' => env('RELAY_TOOLS_CACHE_DURATION', 60), // in minutes (0 to disable)
];
```

## Basic Usage

Here's how you can integrate MCP tools into your Prism agent:

```php
use Prism\Prism\Prism;
use Prism\Relay\Facades\Relay;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withPrompt('Find information about Laravel on the web')
    ->withTools(Relay::tools('puppeteer'))
    ->asText();

return $response->text;
```

The agent can now use any tools provided by the Puppeteer MCP server, such as navigating to webpages, taking screenshots, clicking buttons, and more.

## Real-World Example

Here's a practical example of creating a Laravel command that uses MCP tools with Prism:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Relay\Facades\Relay;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Text\PendingRequest;

use function Laravel\Prompts\note;
use function Laravel\Prompts\textarea;

class MCP extends Command
{
    protected $signature = 'prism:mcp';

    public function handle()
    {
        $response = $this->agent(textarea('Prompt'))->asText();

        note($response->text);
    }

    protected function agent(string $prompt): PendingRequest
    {
        return Prism::text()
            ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
            ->withSystemPrompt(view('prompts.nova-v2'))
            ->withPrompt($prompt)
            ->withTools([
                ...Relay::tools('puppeteer'),
            ])
            ->usingTopP(1)
            ->withMaxSteps(99)
            ->withMaxTokens(8192);
    }
}
```

This command creates an interactive CLI that lets you input prompts that will be sent to Claude. The agent can use Puppeteer tools to browse the web, complete tasks, and return the results.

## Transport Types

Arc supports multiple transport mechanisms:

### HTTP Transport

For MCP servers that communicate over HTTP:

```php
'github' => [
    'url' => env('RELAY_GITHUB_SERVER_URL', 'http://localhost:8000/api'),
    'api_key' => env('RELAY_GITHUB_SERVER_API_KEY'),
    'timeout' => 30,
    'transport' => Transport::Http,
],
```

### STDIO Transport

For locally running MCP servers that communicate via standard I/O:

```php
'puppeteer' => [
    'command' => [
      'npx',
      '-y',
      '@modelcontextprotocol/server-puppeteer',
      '--options',
      // Array values are passed as JSON encoded strings
      [
        'debug' => env('MCP_PUPPETEER_DEBUG', false)
      ]
    ],
    'timeout' => 30,
    'transport' => Transport::Stdio,
    'env' => [
        'NODE_ENV' => 'production',  // Set Node environment
        'MCP_SERVER_PORT' => '3001',  // Set a custom port for the server
    ],
],
```

> [!NOTE]
> The STDIO transport launches a subprocess and communicates with it through standard input/output. This is perfect for running tools directly on your application server.

> [!TIP]
> The `env` option allows you to pass environment variables to the MCP server process. This is useful for configuring server behavior, enabling debugging, or setting authentication details.

## Advanced Usage

### Using Multiple MCP Servers

You can combine tools from multiple MCP servers in a single Prism agent:

```php
use Prism\Prism\Prism;
use Prism\Relay\Facades\Relay;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withTools([
        ...Relay::tools('github'),
        ...Relay::tools('puppeteer')
    ])
    ->withPrompt('Find and take screenshots of Laravel repositories')
    ->asText();
```

### Error Handling

The package uses specific exception types for better error handling:

```php
use Prism\Relay\Exceptions\RelayException;
use Prism\Relay\Exceptions\ServerConfigurationException;
use Prism\Relay\Exceptions\ToolCallException;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\Exceptions\TransportException;

try {
    $tools = Relay::tools('puppeteer');
    // Use the tools...
} catch (ServerConfigurationException $e) {
    // Handle configuration errors (missing server, invalid settings)
    Log::error('MCP Server configuration error: ' . $e->getMessage());
} catch (ToolDefinitionException $e) {
    // Handle issues with tool definitions from the MCP server
    Log::error('MCP Tool definition error: ' . $e->getMessage());
} catch (TransportException $e) {
    // Handle communication errors with the MCP server
    Log::error('MCP Transport error: ' . $e->getMessage());
} catch (ToolCallException $e) {
    // Handle errors when calling a specific tool
    Log::error('MCP Tool call error: ' . $e->getMessage());
} catch (RelayException $e) {
    // Handle any other MCP-related errors
    Log::error('Relay general error: ' . $e->getMessage());
}
```