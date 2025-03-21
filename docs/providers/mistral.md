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

## OCR
Mistral provides an OCR endpoint which can be used to extract text from documents.
This OCR endpoint can be used like this:

```php
use Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\Providers\Mistral\Mistral;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRResponse;

/** @var Mistral $provider */
$provider = Prism::provider(\Prism\Prism\Enums\Provider::Mistral);

/** @var OCRResponse $ocrResponse */
$ocrResponse = $provider->ocr(
    'mistral-ocr-latest',
    Document::fromUrl('https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf')
);

/**
* Just need the full text of all the pages combined? Use the toText() method.
 */
$text = $ocrResponse->toText();
```
::: tip
The OCR endpoint response time can vary depending on the size of the document. We recommend doing this in the background like a queue with a longer timeout.
:::

