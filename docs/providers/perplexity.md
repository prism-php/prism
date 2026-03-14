# Perplexity
## Configuration

```php
'perplexity' => [
    'api_key' => env('PERPLEXITY_API_KEY', ''),
    'url' => env('PERPLEXITY_URL', 'https://api.perplexity.ai'),
]
```

## Documents

Sonar models support document analysis through file uploads. You can provide files either as URLs to publicly accessible documents or as base64 encoded bytes. Ask questions about document content, get summaries, extract information, and perform detailed analysis of uploaded files in multiple formats including PDF, DOC, DOCX, TXT, and RTF.
- The maximum file size is 50MB. Files larger than this limit will not be processed
- Ensure provided HTTPS URLs are publicly accessible
Check it out the [documentation for more details](https://docs.perplexity.ai/guides/file-attachments)

## Images
Sonar models support image analysis through direct image uploads. You can include images in your API requests to support multi-modal conversations alongside text. Images can be provided either as base64 encoded strings within a data URI or as standard HTTPS URLs.
- When using base64 encoding, the API currently only supports images up to 50 MB per image
- Supported formats for base64 encoded images: PNG (image/png), JPEG (image/jpeg), WEBP (image/webp), and GIF (image/gif)
- When using an HTTPS URL, the model will attempt to fetch the image from the provided URL. Ensure the URL is publicly accessible.

## Considerations
### Message Order

- Message order matters. Perplexity is strict about the message order being:

1. `SystemMessage`
2. `UserMessage`
3. `AssistantMessage`

### Additional fields
Perplexity outputs additional fields in the response, such as `citations`, `search_results`, and the `reasoning` that is extracted from the model response. These fields are exposed in the response object
via the property `additionalFields`. e.g `$response->additionalFields['citations']`.

### Structured Output

Perplexity supports two types of structured outputs: JSON Schema and Regex; but currently Prism only supports JSON Schema.

Here's an example of how to use JSON Schema for structured output:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Perplexity, 'sonar-pro')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```