# Prism Server

Prism Server is a powerful feature that allows you to expose your Prism-powered AI models through a standardized API. This makes it easy to integrate your custom AI solutions into various applications, including chat interfaces and other tools that support OpenAI-compatible APIs.

## How It Works

Prism Server acts as a middleware, translating requests from OpenAI-compatible clients into Prism-specific operations. This means you can use tools like ChatGPT web UIs or any OpenAI SDK to interact with your custom Prism models.

## Setting Up Prism Server

### 1. Enable Prism Server

First, make sure Prism Server is enabled in your `config/prism.php` file:

```php
'prism_server' => [
    // The middleware that will be applied to the Prism Server routes.
    'middleware' => [],
    'enabled' => env('PRISM_SERVER_ENABLED', true),
]
```

### 2. Register Your Prisms

To make your Prism models available through the server, you need to register them. This is typically done in a service provider, such as `AppServiceProvider`:

```php
use Prism\Prism\Prism;
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
                ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
                ->withSystemPrompt('You are a helpful assistant.')
        );
    }
}
```

In this example, we're registering a model named `my-custom-model` that uses the Anthropic Claude 3 Sonnet model with a custom system message.

## Using Prism Server

Once set up, Prism Server exposes two main endpoints:

### Chat Completions

To generate text using your registered Prism models:

```bash
curl -X POST "http://your-app.com/prism/openai/v1/chat/completions" \
     -H "Content-Type: application/json" \
     -d '{
  "model": "my-custom-model",
  "messages": [
    {"role": "user", "content": "Hello, who are you?"}
  ]
}'
```

### List Available Models

To get a list of all registered Prism models:

```bash
curl "http://your-app.com/prism/openai/v1/models"
```

## Integration with Open WebUI

Prism Server works seamlessly with OpenAI-compatible chat interfaces like [Open WebUI](https://openwebui.com). Here's an example Docker Compose configuration:

```yaml
services:
  open-webui:
    image: ghcr.io/open-webui/open-webui:main
    ports:
      - "3000:8080"
    environment:
      OPENAI_API_BASE_URLS: "http://laravel:8080/prism/openai/v1"
      WEBUI_SECRET_KEY: "your-secret-key"

  laravel:
    image: serversideup/php:8.3-fpm-nginx
    volumes:
      - ".:/var/www/html"
    environment:
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
    depends_on:
      - open-webui
```

With this setup, you can access your Prism models through a user-friendly chat interface at `http://localhost:3000`.

By leveraging Prism Server, you can create powerful, custom AI experiences while maintaining compatibility with a wide ecosystem of tools and libraries. Whether you're building a chatbot, a content generation tool, or something entirely new, Prism Server provides the flexibility and standardization you need to succeed.

## Adding Middleware

You can add middleware to the Prism Server routes by setting the `middleware` option in your `config/prism.php` file:

```php
'prism_server' => [
    'middleware' => ['api'],
],
```
