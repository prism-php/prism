# Image Generation

Generate stunning images from text prompts using AI-powered models. Prism provides a clean, consistent API for image generation across different providers, starting with comprehensive OpenAI support.

## Getting Started

Creating images with Prism is as simple as describing what you want:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('openai', 'gpt-image-2.6-flare')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
file_put_contents('sea-otter.png', base64_decode($image->base64));
```

## Provider Support

Currently, Prism supports image generation through:

- **OpenAI**: GPT Image models, including GPT Image 2.6 Flare and Sunburst
- **Gemini**: Gemini 2.0 Flash Preview Image Generation, Imagen 4, Imagen 3

Additional providers will be added in future releases as the ecosystem evolves.

## Basic Usage

### Simple Generation

The most straightforward way to generate an image:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.6-flare')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

// Access the generated image
$image = $response->firstImage();
if ($image->hasBase64()) {
    echo "Base64 Image Data: " . $image->base64;
}
```

### Working with Responses

The response object provides helpful methods for accessing generated content:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.6-flare')
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

// Access the raw API response data
$rawResponse = $response->raw;
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Options

OpenAI offers various customization options depending on the model:

GPT Image models return base64-encoded data in the `base64` property. Do not send `response_format`; use `output_format` to choose the image format.

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.6-flare')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'high',
        'background' => 'transparent',
        'output_format' => 'png',
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    // Save the base64 image to a file
    file_put_contents('generated-image.png', base64_decode($image->base64));
    echo "Base64 image saved to generated-image.png";
}
```

#### Image Editing

OpenAI's GPT Image 2.6 models supports editing existing images. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('photos/landscape.png');

$response = Prism::image()
    ->using('openai', 'gpt-image-2.6-flare')
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
    ->using('openai', 'gpt-image-2.6-flare')
    ->withPrompt('Make the colors more vibrant', [
        Image::fromLocalPath('photo1.png'),
        Image::fromLocalPath('photo2.png')->as('custom-name.png'),
    ])
    ->generate();
```

For precise edits, use a mask to specify which areas to modify:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.6-flare')
    ->withPrompt('Replace the sky with a starry night', [
        Image::fromLocalPath('landscape.png'),
    ])
    ->withProviderOptions([
        'mask' => Image::fromLocalPath('sky-mask.png'), // Fully transparent areas will be edited
        'size' => '1024x1024',
        'output_format' => 'png',
    ])
    ->generate();
```

> [!NOTE]
> The PNG mask must match the input image dimensions. Fully transparent areas identify where to edit.

### Gemini Options

Gemini offers customizations, depending on what model is selected. All Gemini image generation models return base64-encoded images only. They also return `mimeType`.

### Gemini Flash Preview Image Generation

Gemini conversational image generation provides the option to edit images by passing them as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('image/boots.png');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
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

## Testing

Prism provides convenient fakes for testing image generation:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;

test('can generate images', function () {
    $fake = PrismFake::create()->image();
    Prism::fake($fake);

    $response = Prism::image()
        ->using('openai', 'gpt-image-2.6-flare')
        ->withPrompt('Test image')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->firstImage()->url)->toContain('fake-image-url');
});
```

Need help with a specific provider or use case? Check the [openai documentation](/providers/openai) or [gemini documentation](/providers/gemini) for detailed configuration options and examples.
