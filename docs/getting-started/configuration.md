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
        'enabled' => env('PRISM_SERVER_ENABLED', false),
    ],
    'request_timeout' => env('PRISM_REQUEST_TIMEOUT', 30),
    'providers' => [
        // Provider configurations here
    ],
];
```

## Request Timeout

Prism includes a global request timeout that applies to all provider HTTP requests. By default, requests will timeout after 30 seconds. You can adjust this value to accommodate longer-running operations like complex generations or large context windows:

```php
'request_timeout' => env('PRISM_REQUEST_TIMEOUT', 30),
```

This timeout applies to both the connection and the overall request duration. If you're working with providers that need more time for complex operations, increase this value accordingly.

Request timeouts can also be set by using the `withClientOptions()` method.

```php
Prism::text()
  ->withClientOptions(['timeout' => 120]) // [!code focus]
  ->asText()
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
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

// Via the third parameter of `using()`
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o', [
        'url' => 'new-base-url'
    ])
    ->withPrompt('Explain quantum computing.')
    ->asText();

// Or via `usingProviderConfig()` (note that this will re-resolve the provider).
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->usingProviderConfig([
        'url' => 'new-base-url'
    ])
    ->withPrompt('Explain quantum computing.')
    ->asText();
```
