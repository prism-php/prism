# Gemini
## Configuration

```php
'gemini' => [
    'api_key' => env('GEMINI_API_KEY', ''),
    'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
],
```

## Search grounding

You may enable Google search grounding on text requests using providerMeta:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

Prism::text()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withPrompt('What is the stock price of Google right now?')
    // Enable search grounding
    ->withProviderMeta(Provider::Gemini, ['searchGrounding' => true])
    ->generate();
```

Note, Prism does not yet support interacting with returned citations.