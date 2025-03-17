# Mistral
## Configuration

```php
'mistral' => [
    'api_key' => env('MISTRAL_API_KEY', ''),
    'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
],
```
## Provider-specific options

## Documents
The text generation part of the exposed Facade only allows documents to be passed in through via URL.
See the [documents](./../input-modalities/documents.md) on how to do that.