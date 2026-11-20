# Images

Prism supports including images in your messages for vision analysis for most providers.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Check that both the provider integration and selected model support the input type.

## Getting started

To add an image to your prompt, use the `withPrompt` method with an `Image` value object:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

// From a local path
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        "What's in this image?",
        [Image::fromLocalPath(path: '/path/to/image.jpg')]
    )
    ->asText();

// From a path on a storage disk
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        "What's in this image?",
        [Image::fromStoragePath(
            path: '/path/to/image.jpg',
            diskName: 'my-disk' // optional - omit/null for default disk
        )]
    )
    ->asText();

// From a URL
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        'Analyze this diagram:',
        [Image::fromUrl(url: 'https://example.com/diagram.png')]
    )
    ->asText();

// From base64
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        'Analyze this diagram:',
        [Image::fromBase64(base64: base64_encode(file_get_contents('/path/to/image.jpg')))]
    )
    ->asText();

// From raw content
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        'Analyze this diagram:',
        [Image::fromRawContent(rawContent: file_get_contents('/path/to/image.jpg'))]
    )
    ->asText();
```

## Alternative: Using withMessages

For an image already uploaded to OpenAI, use its provider-scoped file ID with the Responses API. Your application must authorize the file reference:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

// $openAiImageFileId belongs to the authenticated user's OpenAI integration.
$response = Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withPrompt('Describe this image.', [Image::fromFileId($openAiImageFileId)
        ->withProviderOptions(['detail' => 'high'])])
    ->asText();
```

You can also include images using the message-based approach:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Image;

$message = new UserMessage(
    "What's in this image?",
    [Image::fromLocalPath(path: '/path/to/image.jpg')]
);

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withMessages([$message])
    ->asText();
```

## Customizing Image Filenames

When uploading images, you can provide a custom filename using the fluent `as()` method. This is particularly useful when working with multiple images, as it makes your API requests more readable and helps with debugging:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Edit these images', [
        Image::fromLocalPath('path/to/photo1.png')->as('original-photo.png'),
        Image::fromLocalPath('path/to/photo2.png')->as('reference-image.png'),
    ])
    ->generate();
```

Without custom filenames, images are automatically named using a default pattern. The `as()` method lets you provide meaningful names that make your code more self-documenting.

## Transfer mediums

Providers are not consistent in their support of sending raw contents, base64 and/or URLs (as noted above).

Prism converts between supported representations where possible. The following conversions and limitations apply.

### Supported conversions
- A URL stays a reference. Prism does not download it when reading media content. If the provider requires bytes, fetch them explicitly before making the provider request.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations
- Where a provider only supports URLs: if you provide a file path, raw contents or base64, for security reasons Prism does not create a URL for you and your request will fail.

## Fetching remote media

For a URL supplied by a user or model, use the guarded fetch before passing the image to a provider that needs bytes:

```php
use Prism\Prism\ValueObjects\Media\Image;

$image = Image::fromUrl('https://example.com/diagram.png')
    ->fetchPublicUrlContent();
```

`fetchPublicUrlContent()` rejects non-HTTP schemes, private, loopback and reserved addresses, including hostnames resolving to those addresses. It checks redirect destinations and defaults to five redirects and a 32 MiB response limit. It throws `Prism\Prism\Exceptions\PrismUrlRefused` when a URL, redirect or response size is refused. These checks do not pin DNS results to the eventual connection; use network egress restrictions where that boundary matters.

Use `fetchUrlContent()` only for a URL your application explicitly trusts. It performs an unguarded HTTP fetch, with no public-address, redirect or response-size checks. Neither method runs automatically. The same fetch methods are available on documents, audio and video.
