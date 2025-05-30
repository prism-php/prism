# Configuration

Prism's flexible configuration allows you to easily set up and switch between different AI providers. Let's dive into how you can configure Prism to work with your preferred providers.

## Configuration File

After installation, you'll find the Prism configuration file at `config/prism.php`. If you haven't published it yet, you can do so with:

```bash
php artisan vendor:publish --tag=prism-config
```

Let's break down the key sections of this configuration file:

```php
return [
    'prism_server' => [
        'enabled' => env('PRISM_SERVER_ENABLED', true),
    ],
    'providers' => [
        // Provider configurations here
    ],
];
```

## Provider Configuration

Prism uses a straightforward provider configuration system that lets you set up multiple AI providers in one place. Each provider has its own section in the configuration file where you can specify:

- API credentials
- Base URLs (useful for self-hosted instances or custom endpoints)
- Other Provider-specific settings

Here's a general template for how providers are configured:

```php
'providers' => [
    'provider-name' => [
        'api_key' => env('PROVIDER_API_KEY', ''),
        'url' => env('PROVIDER_URL', 'https://api.provider.com'),
        // Other provider-specific settings
    ],
],
```

## Environment Variables

Prism follows Laravel's environment configuration best practices. All sensitive or environment-specific values should be stored in your `.env` file. Here's how it works:

1. Each provider's configuration pulls values from environment variables
2. Default values are provided as fallbacks
3. Environment variables follow a predictable naming pattern:
   - API keys: `PROVIDER_API_KEY`
   - URLs: `PROVIDER_URL`
   - Other settings: `PROVIDER_SETTING_NAME`

For example:

```shell
# Prism Server Configuration
PRISM_SERVER_ENABLED=true

# Provider Configuration
PROVIDER_API_KEY=your-api-key-here
PROVIDER_URL=https://custom-endpoint.com

```
> [!NOTE]
> Remember to always refer to your chosen provider's documentation pages for the most up-to-date configuration options and requirements specific to that provider.

## Overriding config in your code

You can override config in your code in two ways:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

// Via the third parameter of `using()`
$response = Prism::text()
    ->using(Provider::OpenAI, 'claude-3-5-sonnet-20241022', [
        'url' => 'new-base-url'
    ])
    ->withPrompt('Explain quantum computing.')
    ->asText();

// Or via `usingProviderConfig()` (note that this will re-resolve the provider).
$response = Prism::text()
    ->using(Provider::OpenAI, 'claude-3-5-sonnet-20241022')
    ->usingProviderConfig([
        'url' => 'new-base-url'
    ])
    ->withPrompt('Explain quantum computing.')
    ->asText();
```

## Telemetry Configuration

Prism includes built-in telemetry capabilities to help you monitor and debug your AI integrations. You can configure telemetry in your `config/prism.php` file:

```php
'telemetry' => [
    'enabled' => env('PRISM_TELEMETRY_ENABLED', false),
    'driver' => env('PRISM_TELEMETRY_DRIVER', 'log'),
    'drivers' => [
        'log' => [
            'channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'single'),
        ],
    ],
],
```

### Environment Variables

Configure telemetry using these environment variables:

```env
# Enable or disable telemetry
PRISM_TELEMETRY_ENABLED=true

# Choose your telemetry driver (log, custom drivers)
PRISM_TELEMETRY_DRIVER=log

# For log driver: specify Laravel log channel
PRISM_TELEMETRY_LOG_CHANNEL=single
```

### Available Options

- **enabled**: Turn telemetry on or off globally
- **driver**: Which telemetry driver to use (defaults to 'log')  
- **drivers**: Configuration for each available telemetry driver

> [!TIP]
> Learn more about telemetry capabilities, custom drivers, and observability integration in the [Telemetry documentation](/core-concepts/telemetry).
