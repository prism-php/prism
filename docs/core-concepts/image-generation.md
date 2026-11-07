# Image Generation

Generate or edit images using a supported provider and model. Provider options control output format, size and other model-specific settings.

## Getting Started

Select a provider and model, then supply a prompt:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
file_put_contents('otter.png', base64_decode($image->base64));
```

## OpenAI image examples

Each example uses this prompt and the same size and quality. Model labels identify
the requested model; these API responses did not report a model ID. Costs below
are calculated from returned text/image token usage at the generation date's
standard prices; they are not account invoices. Read the [generation metadata](/assets/examples/openai/metadata.json)
for the exact options and file details.

```php
use Prism\Prism\Facades\Prism;

$prompt =
    'An editorial illustration of the Prism AI ecosystem: a clear glass triangular prism floats ' .
    'above a deep navy workbench. One soft white beam enters; hot pink, cyan and teal light ' .
    'paths emerge and connect a small constellation of abstract tool, memory, search and ' .
    'conversation shapes. Precise geometry, tactile glass, crisp soft shadows, generous ' .
    'negative space, balanced square composition, polished technical magazine cover. Keep the ' .
    'scene abstract and welcoming. No people, faces, lettering, labels, watermarks or ' .
    'third-party logos.';
```

<div class="image-examples">

<section class="image-example">

### gpt-image-2.5-sunburst

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-2.5-sunburst.webp" alt="Glass prism on a navy workbench sends pink, cyan and teal beams toward a wrench, database, magnifying glass and chat bubble." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-2.5-sunburst</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.013700 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

### gpt-image-2.5-flare

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-flare')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-2.5-flare.webp" alt="Floating glass prism sends pink and cyan light ribbons toward translucent tool, database, search and conversation icons." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-2.5-flare</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.013700 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

### gpt-image-2

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-2.webp" alt="Clear prism on a dark platform connects illuminated stacked panels, blue cubes, a magnifying glass and conversation bubbles." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-2</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.053210 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

### gpt-image-1.5

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1.5')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-1.5.webp" alt="Faceted glass prism splits a white beam into pink, cyan and teal paths beside database, search, wrench and conversation symbols." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-1.5</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.038082 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

### gpt-image-1-mini

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1-mini')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-1-mini.webp" alt="Geometric glass prism above flat pink, blue and teal paths ending in abstract tool and conversation shapes on navy." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-1-mini</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.008660 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

### chatgpt-image-latest

```php
$response = Prism::image()
    ->using('openai', 'chatgpt-image-latest')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/chatgpt-image-latest.webp" alt="Glass prism splits light across a navy workbench toward a database, pink chat bubbles, a wrench and gear, and a search panel." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>chatgpt-image-latest</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.037842 calculated from returned token usage.</figcaption>
</figure>

</section>

</div>

`gpt-image-1.5`, `gpt-image-1-mini` and `chatgpt-image-latest` are scheduled to
shut down on December 1, 2026. Use an available current model for new requests;
see [OpenAI’s deprecation schedule](https://developers.openai.com/api/docs/deprecations).

## Provider Support

Currently, Prism supports image generation through:

- **OpenAI**: GPT Image models
- **Gemini**: conversational image generation and Imagen models
- **Qwen**: Qwen-Image (generation), Qwen-Image-Edit (editing & multi-image fusion)
- [Azure](/providers/azure#embeddings-and-images): image generation on a compatible deployment
- [Replicate](/providers/replicate#supported-features): prediction-based generation with model-specific inputs and image-download host settings
- [xAI](/providers/xai#image-generation): image generation with model-specific format and size options

Choose a model available to your account. Provider support for generation does not imply support for editing or every option. Check the [Gemini](/providers/gemini#image-generation) guide for its image model families and current catalog links.

## Basic Usage

### Simple Generation

The most straightforward way to generate an image:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

// Access the generated image
$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "Image URL: " . $image->url;
}
if ($image->hasBase64()) {
    echo "Base64 Image Data: " . $image->base64;
}
```

### Working with Responses

The response object provides helpful methods for accessing generated content:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Abstract geometric patterns in vibrant colors')
    ->withProviderOptions(['n' => 2])
    ->generate();

// Check if images were generated
if ($response->hasImages()) {
    echo "Generated {$response->imageCount()} image(s)";

    // Access all images
    foreach ($response->images as $image) {
        if ($image->hasUrl()) {
            echo "Image: {$image->url}\n";
        }

        if ($image->hasBase64()) {
            echo "Base64 Image: " . substr($image->base64, 0, 50) . "...\n";
        }

        if ($image->hasRevisedPrompt()) {
            echo "Revised prompt: {$image->revisedPrompt}\n";
        }
    }

    // Or just get the first one
    $firstImage = $response->firstImage();
}

// Check usage information
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Model used: {$response->meta->model}";

// Access the raw API response data
$rawResponse = $response->raw;
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Options

OpenAI offers various customization options depending on the model:

#### GPT Image (Base64 Only)

GPT Image models return base64 data. Do not send the older `response_format` parameter:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1024x1024',              // 1024x1024, 1536x1024, 1024x1536, auto
        'quality' => 'high',                // auto, high, medium, low
        'background' => 'transparent',      // transparent, opaque, auto
        'output_format' => 'png',           // png, jpeg, webp
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    // Save the base64 image to a file
    file_put_contents('generated-image.png', base64_decode($image->base64));
    echo "Base64 image saved to generated-image.png";
}
```

#### Response formats

GPT Image returns base64 data. Use `output_format` to select PNG, JPEG or WebP; omit `response_format`. Other providers can return a URL instead, so inspect `hasBase64()` and `hasUrl()` before saving a result. Follow the [current OpenAI image guide](https://developers.openai.com/api/docs/guides/image-generation) for model options. DALL-E models and `gpt-image-1` have been removed from the API; see [OpenAI's deprecation schedule](https://developers.openai.com/api/docs/deprecations).

#### Image Editing

OpenAI's `gpt-image-2.5-sunburst` model supports editing existing images. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('photos/landscape.png');

$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Add a vaporwave sunset to the background', [$originalImage])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();

// The edited image is returned as base64
$editedImage = $response->firstImage();
file_put_contents('edited-landscape.png', base64_decode($editedImage->base64));
```

You can edit multiple images at once:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Make the colors more vibrant', [
        Image::fromLocalPath('photo1.png'),
        Image::fromLocalPath('photo2.png')->as('custom-name.png'),
    ])
    ->generate();
```

For precise edits, use a mask to specify which areas to modify:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Replace the sky with a starry night', [
        Image::fromLocalPath('landscape.png'),
    ])
    ->withProviderOptions([
        'mask' => Image::fromLocalPath('sky-mask.png'), // Transparent areas identify the edit region
        'size' => '1024x1024',
        'output_format' => 'png',
    ])
    ->generate();
```

> [!NOTE]
> Use a PNG mask with an alpha channel: transparent pixels identify the area to edit. Keep the mask dimensions aligned with the input. The model uses the mask as guidance, so edits can extend beyond its exact outline.

### Gemini Options

Gemini offers customizations, depending on what model is selected. All Gemini image generation models return base64-encoded images only. They also return `mimeType`.

### Gemini image generation

Gemini conversational image generation provides the option to edit images by passing them as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('image/boots.png');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.5-flash-image')
    ->withPrompt('Actually, could we make those boots red?', [$originalImage])
    ->generate();
```

### Imagen Options

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'imagen-4.0-generate-001')
    ->withPrompt('Generate an image of a magnificent building falling into the ocean')
    ->withProviderOptions([
        'n' => 3,                               // number of images to generate
        'size' => '2K',                         // 1K (default), 2K
        'aspect_ratio' => '16:9',               // 1:1 (default), 3:4, 4:3, 9:16, 16:9
        'person_generation' => 'dont_allow',    // dont_allow, allow_adult, allow_all
    ])
    ->generate();
```

### Qwen Options

#### Image Generation

Qwen provides image generation through `qwen-image-max` and `qwen-image-plus` models:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-max')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1328*1328',                         // Image resolution (width*height)
        'negative_prompt' => 'low quality, blurry',    // Content to avoid
        'prompt_extend' => true,                       // Enable prompt rewriting
        'watermark' => false,                          // Disable watermark
        'seed' => 42,                                  // Random seed for reproducibility
    ])
    ->generate();
```

> [!IMPORTANT]
> Generated image URLs are valid for **24 hours** only. Download and save images promptly.

#### Image Editing

Qwen's `qwen-image-edit-max` and `qwen-image-edit-plus` models support single-image editing and multi-image fusion. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Generate an image following this depth map: a red bicycle on a muddy path with dense forest', [
        Image::fromUrl('https://example.com/depth-map.png'),
    ])
    ->withProviderOptions([
        'n' => 2,                              // Output 1-6 images (max/plus models)
        'size' => '1536*1024',                 // Custom output resolution
        'negative_prompt' => 'low quality',    // Content to avoid
        'prompt_extend' => true,               // Enable prompt rewriting
        'watermark' => false,                  // Disable watermark
    ])
    ->generate();
```

For multi-image fusion, pass multiple images — the model can combine elements from up to 3 input images:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('The girl in image 1 wearing the black dress from image 2, sitting in the pose of image 3', [
        Image::fromUrl('https://example.com/person.png'),
        Image::fromUrl('https://example.com/dress.png'),
        Image::fromUrl('https://example.com/pose.png'),
    ])
    ->withProviderOptions([
        'n' => 2,
        'size' => '1024*1536',
    ])
    ->generate();
```

You can also pass images from local files or base64 data:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Add a sunset sky to the background', [
        Image::fromLocalPath('/path/to/photo.png'),
    ])
    ->generate();
```

## Input and configuration limits

Pass editing inputs as `Image` objects in the second argument to `withPrompt()`. The image builder discards other media types from that additional-content array. It does not fetch URLs automatically; a provider that needs bytes requires an explicit fetch or local/raw image content.

`withMaxTokens()`, `usingTemperature()` and system-prompt setters are available through shared builder configuration, but these values are not included in the image request sent to its provider. Use the documented provider image options for generation controls.

Catch `PrismException` for unsupported provider actions or invalid provider responses. Wrong or unsupported input media can be refused by the provider-specific mapper. A generated result can contain a URL, base64 data, or both; check availability before saving it. Do not pass a returned URL to an unguarded HTTP fetch without your application's URL policy.

## Testing

Use fakes to test image generation without external API calls:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\ImageResponseFake;

test('can generate images', function () {
    Prism::fake([ImageResponseFake::make()]);

    $response = Prism::image()
        ->using('openai', 'gpt-image-2.5-sunburst')
        ->withPrompt('Test image')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->firstImage()->url)->toContain('fake-image.png');
});
```

See the [OpenAI](/providers/openai), [Gemini](/providers/gemini) and [Qwen](/providers/qwen) guides for provider-specific options.
