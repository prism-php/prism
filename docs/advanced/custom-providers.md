# Custom Providers

Want to add support for a new AI provider in Prism? This guide will walk you through creating and registering your own custom provider implementation.

## Provider Interface

All providers must implement the `Prism\Prism\Contracts\Provider` interface:

```php
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

interface Provider
{
    public function text(TextRequest $request): TextResponse;

    public function structured(StructuredRequest $request): StructuredResponse;

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;
}
```

The interface is intentionally simple, requiring just one method to handle text generation requests.

## Registration Process

Once you've created your provider, register it with Prism in a service provider:

```php
namespace App\Providers;

use App\Prism\Providers\MyCustomProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['prism-manager']->extend('my-custom-provider', function ($app, $config) {
            return new MyCustomProvider(
                apiKey: $config['api_key'] ?? '',
            );
        });
    }
}
```

Then add your provider configuration to `config/prism.php`:

```php
return [
    'providers' => [
        // ... other providers ...
        'my-custom-provider' => [
            'api_key' => env('MY_CUSTOM_PROVIDER_API_KEY'),
        ],
    ],
];
```

Now you can use your custom provider:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('my-custom-provider', 'model-name')
    ->withPrompt('Hello, custom AI!')
    ->generate();
```
