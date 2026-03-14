# Moderation

Moderate content by checking content against AI-powered models! Moderation helps you detect potentially harmful or inappropriate content before it reaches your users or models.

## Quick Start

Here's how to check text content with just a few lines of code:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Your text to check goes here')
    ->asModeration();

// Check if any content was flagged
if ($response->isFlagged()) {
    // Handle flagged content
    $flagged = $response->firstFlagged();
}
```

## Checking Multiple Inputs

You can check multiple text inputs at once using the unified `withInput()` method:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::moderation()
    ->using(Provider::OpenAI)
    // Multiple inputs as variadic arguments
    ->withInput('First text to check', 'Second text to check', 'Third text')
    ->asModeration();

// Or pass an array
$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput(['First text', 'Second text', 'Third text'])
    ->asModeration();

// Get all flagged results
$flaggedResults = $response->flagged();

foreach ($flaggedResults as $result) {
    // Handle each flagged result
    $categories = $result->categories;
    $scores = $result->categoryScores;
}
```

## Image Moderation

You can also moderate images! This is useful for checking user-uploaded images for inappropriate content:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(Image::fromUrl('https://example.com/image.png'))
    ->asModeration();

if ($response->isFlagged()) {
    // Handle flagged image
}
```

### Mixed Text and Image Moderation

You can check both text and images in a single request using the unified `withInput()` method:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

// Mix text and images as variadic arguments
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(
        'Check this text',
        Image::fromUrl('https://example.com/image.png'),
        'Another text to check',
        Image::fromLocalPath('/path/to/image1.jpg')
    )
    ->asModeration();

// Or use arrays
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput([
        'Text 1',
        Image::fromUrl('https://example.com/image.png'),
        'Text 2',
        Image::fromLocalPath('/path/to/image2.jpg'),
    ])
    ->asModeration();
```

> [!IMPORTANT]
> **Text as Image Context**: When mixing text and images in a single request, text inputs are treated as context/descriptions for the images, not as separate moderation inputs. This means:
> - Multiple text inputs alone will return multiple results (one per text input)
> - Multiple images alone will return multiple results (one per image)
> - Text + Image combinations will return one result per image, with text serving as context for the image
> 
> If you need separate moderation results for text and images, make separate API calls for each type.

## Input Methods

Prism provides several methods for adding inputs to moderation requests.

### Using withInput()

The `withInput()` method is the unified way to add any type of input to moderation. It accepts strings, Image objects, or arrays of either as variadic arguments. This is the recommended method for most use cases:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

// Single text input
$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Check this text for moderation')
    ->asModeration();

// Multiple text inputs
$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Text 1', 'Text 2', 'Text 3')
    ->asModeration();

// Single image
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(Image::fromUrl('https://example.com/image.png'))
    ->asModeration();

// Multiple images
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput([
        Image::fromUrl('https://example.com/image1.png'),
        Image::fromLocalPath('/path/to/image2.jpg'),
    ])
    ->asModeration();

// Mixed text and images
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(
        'Text to check',
        Image::fromUrl('https://example.com/image.png'),
        'More text',
        Image::fromBase64($base64Data, 'image/jpeg')
    )
    ->asModeration();
```

### Image Sources

Images can be created from various sources:

```php
use Prism\Prism\ValueObjects\Media\Image;

// From a URL
Image::fromUrl('https://example.com/image.png')

// From a local file
Image::fromLocalPath('/path/to/image.jpg')

// From a storage disk
Image::fromStoragePath('/path/to/image.jpg', 'my-disk')

// From base64
Image::fromBase64($base64Data, 'image/jpeg')
```

> [!NOTE]
> Image moderation requires the `omni-moderation-latest` model (or similar image-capable moderation models). Make sure to specify the correct model when using image moderation.

## Response Handling

The moderation response provides everything you need to handle flagged content:

```php
use Prism\Prism\Moderation\Response;
use Prism\Prism\ValueObjects\ModerationResult;

// Check if any content was flagged
if ($response->isFlagged()) {
    // Get the first flagged result
    $firstFlagged = $response->firstFlagged();
    
    // Or get all flagged results
    $allFlagged = $response->flagged();
}

// Access individual results
foreach ($response->results as $result) {
    /** @var ModerationResult $result */
    $isFlagged = $result->flagged;
    $categories = $result->categories; // Array of category => bool
    $categoryScores = $result->categoryScores; // Array of category => float
}

// Access response metadata
$meta = $response->meta;
$model = $meta->model; // The model used for moderation
$id = $meta->id; // Unique identifier for the moderation request
$rateLimits = $meta->rateLimits; // Rate limit information
```

### Understanding Results

Each moderation result includes:

- **`flagged`**: A boolean indicating if the content was flagged as potentially harmful
- **`categories`**: An array mapping category names to boolean values indicating if that category was detected
- **`categoryScores`**: An array mapping category names to float values indicating the confidence level

The response also includes a `meta` object with:

- **`id`**: A unique identifier for the moderation request
- **`model`**: The model used for moderation (e.g., 'omni-moderation-latest')
- **`rateLimits`**: Rate limit information from the API response

```php
$result = $response->results[0];

if ($result->flagged) {
    // Check specific categories
    if ($result->categories['hate'] ?? false) {
        // Handle hate content
    }
    
    if ($result->categories['harassment'] ?? false) {
        // Handle harassment
    }
    
    // Check scores for more nuanced handling
    $hateScore = $result->categoryScores['hate'] ?? 0.0;
    if ($hateScore > 0.5) {
        // High confidence of hate content
    }
}
```

## Common Settings

You can fine-tune your moderation requests just like other Prism features:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput('Your text here')
    ->withClientOptions(['timeout' => 30]) // Adjust request timeout
    ->withClientRetry(3, 100) // Add automatic retries
    ->withProviderOptions([
        // Provider-specific options
    ])
    ->asModeration();
```

## Error Handling

Always handle potential errors gracefully:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;

try {
    $response = Prism::moderation()
        ->using(Provider::OpenAI)
        ->withInput('Your text here')
        ->asModeration();
        
    if ($response->isFlagged()) {
        // Handle flagged content
    }
} catch (PrismException $e) {
    Log::error('Moderation check failed:', [
        'error' => $e->getMessage()
    ]);
}
```

## Use Cases

Moderation is useful for:

- **User-Generated Content**: Check comments, posts, or messages before displaying them
- **Content Filtering**: Filter out inappropriate content in chat applications
- **Image Moderation**: Verify user-uploaded images meet platform guidelines
- **Pre-Processing**: Check inputs before sending them to other AI models
- **Compliance**: Ensure content meets platform guidelines and policies
- **Mixed Content**: Check both text and images together in a single request

## Pro Tips

**Thresholds**: Use category scores to implement custom thresholds. Different applications may need different sensitivity levels.

**Batch Processing**: Check multiple inputs in a single request for better performance and efficiency.

**Caching**: Consider caching moderation results for repeated content to reduce API calls.

**Logging**: Always log flagged content for audit trails and to improve your filtering over time.

> [!IMPORTANT]
> Different providers may have different category names and scoring systems. Always check your provider's documentation for specific details about available categories and score interpretations.


