# Documents

Prism currently supports documents with Gemini and Anthropic.

## Supported file types

Different providers support different document types.

At the time of writing:
- Anthropic supports 
    - pdf (application/pdf) 
    - txt (text/plain)
    - md (text/md)
- Gemini supports:
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
- Mistral supports:
  - PDF (application/pdf)
  - CSV (text/csv)
  - text files (text/plain)
- OpenAI supports:
    - PDF (application/pdf)
    - `file_id` (previously uploaded pdf file id.)

All of these formats should work with Prism.

## Getting started

To add an image to your message, add a `Document` value object to the `additionalContent` property:

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\OpenAIFile;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        // From base64
        new UserMessage('Here is the document from base64', [
            Document::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')), 'application/pdf'),
        ]),
        // Or from a path
        new UserMessage('Here is the document from a local path', [
            Document::fromPath('tests/Fixtures/test-pdf.pdf'),
        ]),
        // Or from a text string
        new UserMessage('Here is the document from a text string (e.g. from your database)', [
            Document::fromText('Hello world!'),
        ]),
        // Or from an URL
        new UserMessage('Here is the document from a url (make sure this is publically accessable)', [
            Document::fromUrl('https://example.com/test-pdf.pdf'),
        ]),
        // Or from a file_id
        new UserMessage('Here is the document from file_id', [
            new OpenAIFile('file-lsfgSXyV2xEb8gw8fYjXU6'),
        ]),
    ])
    ->generate();

```
