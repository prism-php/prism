# Documents

Prism currently supports documents with Gemini and Anthropic.

## Supported file types

Different providers support different mime types (and transfer mediums).

At the time of writing:
- Anthropic supports (file contents or url)
    - pdf (application/pdf) 
    - txt (text/plain)
    - md (text/md)
    - chunks (array of strings)
- Gemini supports (file contents or url):
    - pdf (application/pdf)
    - javascript (text/javascript)
    - python (text/x-python)
    - txt (text/plain)
    - html (text/html)
    - css (text/css)
    - md (text/md)
    - csv (text/csv)
    - xml (text/xml)
    - rtf (text/rtf)
- Mistral supports (url only):
  - PDF (application/pdf)
  - CSV (text/csv)
  - text files (text/plain)
- OpenAI supports (file contents, url or file_id):
    - PDF (application/pdf)

All of these formats should work with Prism.

## Supported transfer mediums 

Providers are not consistent in their support of sending file contents and/or URLs (as noted above). 

Prism tries to smooth over these rough edges, but its not always possible.

In summary:
- Where a provider only supports URLs: if you provide a file path, contents, base64 or chunks, for security reasons Prism does not create a URL for you and your request will fail.
- Where a provider does not support URLs: Prism will fetch the URL and use the contents.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.
- Where you provide chunks and the provider does not support them: Prism will convert your chunks to rawContent or base64 (depending on what the provider accepts), which each chunk separated by a new line.

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
