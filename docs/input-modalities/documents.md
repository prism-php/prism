# Documents

Prism supports including documents in your messages with some providers.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Check that both the provider integration and selected model support the input type.

## Provider input forms

| Integration | Accepted document forms |
|---|---|
| Anthropic | Bytes, text, chunks, URL or an Anthropic file ID |
| OpenAI Responses API | Bytes, URL or an OpenAI file ID; chunks are refused |
| Mistral OCR | URL only; local bytes and chunks are refused |
| Gemini | Bytes or a recognized Gemini File API URI; arbitrary document URLs need an explicit fetch |
| OpenRouter / Requesty | Bytes or URL; file IDs and chunks are refused |

File IDs belong to their originating provider and account. Authorize access before sending them. Anthropic maps a `text/*` document to a `text/plain` source and keeps its text unchanged; use `fromText()` or decodable text bytes. `fromChunks()` takes an array of strings and is an Anthropic document form, not a generic provider chunk format.

## Supported file types

> [!TIP]
> If provider interoperability is important to your app, we recommend converting documents to markdown.

Please check provider documentation for supported file/mime types, as support differs widely.

The most supported file types are pdf and text/plain (which may include markdown).

## Transfer mediums

> [!TIP]
> If provider interoperability is important to your app, we recommend using rawContent or base64.

Providers are not consistent in their support of sending file raw contents, base64 and/or URLs.

Prism converts between supported representations where possible. The following conversions and limitations apply.

### Supported conversions

- A URL stays a reference. If your provider needs document bytes, call `fetchPublicUrlContent()` explicitly for a user- or model-supplied URL. Use the unguarded `fetchUrlContent()` only for URLs your application trusts. See [Fetching remote media](/input-modalities/images#fetching-remote-media) for limits and refusals.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations

- Where a provider only supports URLs: if you provide a file path, raw contents, base64 or chunks, for security reasons Prism does not create a URL for you and your request will fail.
- Chunks cannot be passed between providers, as they could be in different formats (however, currently only Anthropic supports them).

## Getting started

To add a document to your prompt, use the `withPrompt` method with a `Document` value object:

These examples assume your application has authorized the local/storage paths and supplies validated PDF bytes as `$rawContent`, their base64 encoding as `$baseFromDB`, and any provider file ID. Replace the example paths with files on your configured disks.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Document;

// From a local path
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Analyze this document',
        [Document::fromLocalPath(
            path: 'tests/Fixtures/test-pdf.pdf',
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From a storage path
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Summarize this document',
        [Document::fromStoragePath(
            path: 'mystoragepath/file.pdf',
            diskName: 'my-disk', // optional - omit/null for default disk
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From base64
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Extract key points from this document',
        [Document::fromBase64(
            document: $baseFromDB,
            mimeType: 'application/pdf',
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From raw content
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Review this document',
        [Document::fromRawContent(
            rawContent: $rawContent,
            mimeType: 'application/pdf',
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From a text string
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Process this text document',
        [Document::fromText(
            text: 'Hello world!',
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From an URL
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Analyze this document from URL',
        [Document::fromUrl(
            url: 'https://example.com/test-pdf.pdf',
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From chunks
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Process this chunked document',
        [Document::fromChunks(
            chunks: [
                'chunk one',
                'chunk two'
            ],
            title: 'My document title' // optional
        )]
    )
    ->asText();

// From an application-authorized Anthropic file ID ($anthropicFileId)
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(
        'Analyze this document from provider file',
        [Document::fromFileId(
            fileId: $anthropicFileId
        )]
    )
    ->asText();
```

## Alternative: Using withMessages

You can also include documents using the message-based approach:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

$message = new UserMessage(
    'Analyze this document',
    [Document::fromLocalPath(
        path: 'tests/Fixtures/test-pdf.pdf',
        title: 'My document title' // optional
    )]
);

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withMessages([$message])
    ->asText();
```

Or, if using a provider file_id - use fromFileId:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4.1')
    ->withPrompt(
        'Analyze this OpenAI file',
        [Document::fromFileId(
            fileId: 'file-lsfgSXyV2xEb8gw8fYjXU6'
        )]
    )
    ->asText();
```
