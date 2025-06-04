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

### Metadata

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'meta' => [ // [!code focus]
            'project_id' => 23 // [!code focus]
        ] // [!code focus]
    ]) // [!code focus]
```

### Previous Responses

Prism supports OpenAI's [conversation state](https://platform.openai.com/docs/guides/conversation-state#openai-apis-for-conversation-state) with the `previous_response_id` parameter.

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'previous_response_id' => 'response_id' // [!code focus]
    ]) // [!code focus]
```

### Truncation

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'truncation' => 'auto' // [!code focus]
    ]) // [!code focus]
```

### Caching

Automatic caching does not currently work with JsonMode. Please ensure you use StructuredMode if you wish to utilise automatic caching.
