# OpenAI
## Configuration

```php
'openai' => [
    'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
    'api_key' => env('OPENAI_API_KEY', ''),
    'organization' => env('OPENAI_ORGANIZATION', null),
]
```

## Provider-specific options
### Strict Tool Schemas

Prism supports OpenAI's [function calling with Structured Outputs](https://platform.openai.com/docs/guides/function-calling#function-calling-with-structured-outputs) via provider-specific meta.

```php
Tool::as('search') // [!code focus]
    ->for('Searching the web')
    ->withStringParameter('query', 'the detailed search query')
    ->using(fn (): string => '[Search results]')
    ->withProviderOptions([ // [!code focus]
      'strict' => true, // [!code focus]
    ]); // [!code focus]
```

### Strict Structured Output Schemas

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'schema' => [ // [!code focus]
            'strict' => true // [!code focus]
        ] // [!code focus]
    ]) // [!code focus]
```

### Metadata

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'meta' => [ // [!code focus]
            'project_id' => 23 // [!code focus]
        ] // [!code focus]
    ]) // [!code focus]
```

### Previous Responses

Prism supports OpenAI's [conversation state](https://platform.openai.com/docs/guides/conversation-state#openai-apis-for-conversation-state) with the `previous_response_id` parameter.

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'previous_response_id' => 'response_id' // [!code focus]
    ]) // [!code focus]
```

### Truncation

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'truncation' => 'auto' // [!code focus]
    ]) // [!code focus]
```

### Caching

Automatic caching does not currently work with JsonMode. Please ensure you use StructuredMode if you wish to utilise automatic caching.

### Code interpreter

You can use the OpenAI code interpreter as follows:

```php
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withPrompt('Solve the equation 3x + 10 = 14.')
    ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
    ->asText();
```

### Additional Message Attributes

Adding optional parameters to a `UserMessage` like the `name` field can be done through the `additionalAttributes` parameter.

```php
Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withMessages([
        new UserMessage('Who are you?', additionalAttributes: ['name' => 'TJ']),
    ])
    ->asText()
```

## Image Generation

OpenAI provides powerful image generation capabilities through multiple models. Prism supports all of OpenAI's image generation models with their full feature sets.

### Supported Models

| Model | Description |
|-------|-------------|
| `dall-e-3` | Latest DALL-E model |
| `dall-e-2` | Previous generation |
| `gpt-image-1` | GPT-based image model |

### Basic Usage

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

$image = $response->firstImage();
echo $image->url; // Generated image URL
```

### DALL-E 3 Options

DALL-E 3 is the most advanced model with the highest quality output:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A futuristic cityscape with flying cars')
    ->withProviderOptions([
        'size' => '1792x1024',          // 1024x1024, 1024x1792, 1792x1024
        'quality' => 'hd',              // standard, hd
        'style' => 'vivid',             // vivid, natural
    ])
    ->generate();

// DALL-E 3 automatically revises prompts for better results
if ($response->firstImage()->hasRevisedPrompt()) {
    echo "Revised prompt: " . $response->firstImage()->revisedPrompt;
}
```

### DALL-E 2 Options

DALL-E 2 supports generating multiple images and is more cost-effective:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-2')
    ->withPrompt('Abstract geometric patterns')
    ->withProviderOptions([
        'n' => 4,                       // Number of images (1-10)
        'size' => '1024x1024',          // 256x256, 512x512, 1024x1024
        'response_format' => 'url',     // url only
        'user' => 'user-123',           // Optional user identifier
    ])
    ->generate();

// Process multiple images
foreach ($response->images as $image) {
    echo "Image: {$image->url}\n";
}
```

### GPT-Image-1 Options

GPT-Image-1 offers advanced features including image editing and format control:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('A detailed architectural rendering of a modern house')
    ->withProviderOptions([
        'size' => '1536x1024',              // Various sizes supported
        'quality' => 'high',                // standard, high
        'output_format' => 'webp',          // png, webp, jpeg
        'output_compression' => 85,         // Compression level (0-100)
        'background' => 'transparent',      // transparent, white, black
        'moderation' => true,               // Enable content moderation
    ])
    ->generate();
```

### Image Editing with GPT-Image-1

GPT-Image-1 supports sophisticated image editing operations:

```php
// Load your source image and mask
$originalImage = base64_encode(file_get_contents('/path/to/photo.jpg'));
$maskImage = base64_encode(file_get_contents('/path/to/mask.png'));

$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Replace the sky with a dramatic sunset')
    ->withProviderOptions([
        'image' => $originalImage,          // Base64 encoded original image
        'mask' => $maskImage,               // Base64 encoded mask (optional)
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();
```

### Response Format

Generated images are returned as URLs:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('Digital artwork')
    ->generate();

$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "<img src='{$image->url}' alt='Generated image'>";
}
```
