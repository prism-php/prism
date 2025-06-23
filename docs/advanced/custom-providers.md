# Custom Providers

Want to add support for a new AI provider in Prism? This guide will walk you through creating and registering your own custom provider implementation.

## Provider Interface

All providers must extend the `Prism\Prism\Providers\Provider` class.

The abstract class has a default method for all current required methods, though this may change.

Each provider should:
- Overwrite the methods for the actions it supports. 
- Overwrite the `handleRequestExceptions` method if supports advanced request exceptions.

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
    ->asText();
```
