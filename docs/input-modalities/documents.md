# Documents

Prism supports including documents in your messages with some providers.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Note however that provider support may differ by model. If you receive error messages with a provider that Prism indicates is supported, check the provider's documentation as to whether the model you are using supports documents.

## Supported file types

> [!TIP]
> If provider interoperability is important to your app, we recommend converting documents to markdown.

Please check provider documentation for supported file/mime types, as support differs widely.

The most supported file types are pdf and text/plain (which may include markdown).

## Transfer mediums 

> [!TIP]
> If provider interoperability is important to your app, we recommend using rawContent or base64.

Providers are not consistent in their support of sending file raw contents, base64 and/or URLs. 

Prism tries to smooth over these rough edges, but its not always possible.

### Supported conversions
- Where a provider does not support URLs: Prism will fetch the URL and use base64 or rawContent.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations

- Where a provider only supports URLs: if you provide a file path, raw contents, base64 or chunks, for security reasons Prism does not create a URL for you and your request will fail.
- Chunks cannot be passed between providers, as they could be in different formats (however, currently only Anthropic supports them).

## Getting started

To add a document to your message, add a `Document` value object to the `additionalContent` property:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\OpenAIFile;

Prism::text()
    ->using('my-provider', 'my-model')
    ->withMessages([
        // From a local path
        new UserMessage('Here is the document from a local path', [
            Document::fromLocalPath(
                path: 'tests/Fixtures/test-pdf.pdf', 
                title: 'My document title' // optional
            ),
        ]),
        // From a storage path
        new UserMessage('Here is the document from a storage path', [
            Document::fromStoragePath(
                path: 'mystoragepath/file.pdf', 
                disk: 'my-disk', // optional - omit/null for default disk
                title: 'My document title' // optional
            ),
        ]),
        // From base64
        new UserMessage('Here is the document from base64', [
            Document::fromBase64(
                base64: $baseFromDB, 
                mimeType: 'optional/mimetype', // optional 
                title: 'My document title' // optional
            ),
        ]),
        // From raw content
        new UserMessage('Here is the document from raw content', [
            Document::fromRawContent(
                rawContent: $rawContent, 
                mimeType: 'optional/mimetype', // optional 
                title: 'My document title' // optional
            ),
        ]),
        // From a text string
        new UserMessage('Here is the document from a text string (e.g. from your database)', [
            Document::fromText(
                text: 'Hello world!', 
                title: 'My document title' // optional
            ),
        ]),
        // From an URL
        new UserMessage('Here is the document from a url (make sure this is publically accessible)', [
            Document::fromUrl(
                url: 'https://example.com/test-pdf.pdf', 
                title: 'My document title' // optional
            ),
        ]),
        // From chunks
        new UserMessage('Here is a chunked document', [
            Document::fromChunks(
                chunks: [
                    'chunk one',
                    'chunk two'
                ], 
                title: 'My document title' // optional
            ),
        ]),
    ])
    ->asText();

```

Or, if using an OpenAI file_id - add an `OpenAIFile`:

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\OpenAIFile;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage('Here is the document from file_id', [
            new OpenAIFile('file-lsfgSXyV2xEb8gw8fYjXU6'),
        ]),
    ])
    ->asText();
```
