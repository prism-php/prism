# OpenAI
## Configuration

```php
'openai' => [
    'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
    'api_key' => env('OPENAI_API_KEY', ''),
    'organization' => env('OPENAI_ORGANIZATION', null),
]
```

## Provider-specific options
### Strict Tool Schemas

Prism supports OpenAI's [function calling with Structured Outputs](https://platform.openai.com/docs/guides/function-calling#function-calling-with-structured-outputs) via provider-specific meta.

```php
Tool::as('search') // [!code focus]
    ->for('Searching the web')
    ->withStringParameter('query', 'the detailed search query')
    ->using(fn (): string => '[Search results]')
    ->withProviderOptions([ // [!code focus]
      'strict' => true, // [!code focus]
    ]); // [!code focus]
```

### Strict Structured Output Schemas

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'schema' => [ // [!code focus]
            'strict' => true // [!code focus]
        ] // [!code focus]
    ]) // [!code focus]
```

## Limitations
### Tool Choice

OpenAI does not support `ToolChoice::Any` when using `withToolChoice()`.

### Caching

Automatic caching does not currently work with JsonMode. Please ensure you use StructuredMode if you wish to utilise automatic caching.
