# Image Generation

Generate stunning images from text prompts using AI-powered models. Prism provides a clean, consistent API for image generation across different providers, starting with comprehensive OpenAI support.

## Getting Started

Creating images with Prism is as simple as describing what you want:

```php
use Prism\Prism\Prism;

$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
echo $image->url; // https://oaidalleapiprodscus.blob.core.windows.net/...
```

## Provider Support

Currently, Prism supports image generation through:

- **OpenAI**: DALL-E 2, DALL-E 3, and GPT-Image-1 models

Additional providers will be added in future releases as the ecosystem evolves.

## Basic Usage

### Simple Generation

The most straightforward way to generate an image:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
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
    ->using('openai', 'dall-e-2')
    ->withPrompt('Abstract geometric patterns in vibrant colors')
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
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Options

OpenAI offers various customization options depending on the model:

#### DALL-E 3 Options

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A beautiful sunset over mountains')
    ->withProviderOptions([
        'size' => '1792x1024',          // 1024x1024, 1024x1792, 1792x1024
        'quality' => 'hd',              // standard, hd
        'style' => 'vivid',             // vivid, natural
        'response_format' => 'url',     // url, b64_json
    ])
    ->generate();
```

#### GPT-Image-1 (Base64 Only)

The GPT-Image-1 model always returns base64-encoded images, regardless of the `response_format` setting:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1024x1024',              // 1024x1024, 1536x1024, 1024x1536, auto
        'quality' => 'high',                // auto, high, medium, low
        'background' => 'transparent',      // transparent, opaque, auto
        'output_format' => 'png',           // png, jpeg, webp
        'output_compression' => 90,         // 0-100 (for jpeg/webp)
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    // Save the base64 image to a file
    file_put_contents('generated-image.png', base64_decode($image->base64));
    echo "Base64 image saved to generated-image.png";
}
```

#### Base64 vs URL Responses

Different models return images in different formats:

- **GPT-Image-1**: Always returns base64-encoded images in the `base64` property
- **DALL-E 2 & 3**: Return URLs by default, but can return base64 when `response_format` is set to `'b64_json'`

```php
// Request base64 format from DALL-E 3
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('Abstract art')
    ->withProviderOptions([
        'response_format' => 'b64_json',
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    echo "Received base64 image data";
}
```

## Testing

Prism provides convenient fakes for testing image generation:

```php
use Prism\Prism\Prism;
use Prism\Prism\Testing\PrismFake;

test('can generate images', function () {
    $fake = PrismFake::create()->image();
    Prism::fake($fake);

    $response = Prism::image()
        ->using('openai', 'dall-e-3')
        ->withPrompt('Test image')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->firstImage()->url)->toContain('fake-image-url');
});
```

Need help with a specific provider or use case? Check the [provider documentation](/providers/openai) for detailed configuration options and examples.
