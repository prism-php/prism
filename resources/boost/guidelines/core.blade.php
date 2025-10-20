## Prism

Prism is a Laravel package for integrating LLMs with a fluent API. Supports OpenAI, Anthropic, Ollama, Mistral, Groq, XAI, Gemini, VoyageAI, ElevenLabs, DeepSeek, and OpenRouter. Use `Prism` facade or `prism()` helper for all interactions.

### Basic Usage
`Prism::text()->using(Provider::OpenAI, 'gpt-4')->withPrompt('prompt')->asText()` - Text generation
`Prism::structured()->using(provider, model)->withSchema($schema)->withPrompt('prompt')->asStructured()` - Structured output
`Prism::text()->asStream()` - Streaming responses
`Prism::embeddings()->fromInput('text')->asEmbeddings()` - Vector embeddings
`Prism::image()->withPrompt('prompt')->generate()` - Image generation
`Prism::audio()->withInput($audio)->asText()` - Speech-to-text

### Structured Output
**CRITICAL**: Root schema MUST be `ObjectSchema` for OpenAI. Other schema types only as properties.

```php
$schema = new ObjectSchema('product', 'Product info', [
    new StringSchema('name', 'Product name'),
    new NumberSchema('price', 'Price in dollars'),
], ['name', 'price']);

$response = Prism::structured()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSchema($schema)
    ->withPrompt('Generate a product')
    ->asStructured();
```

### Streaming
```php
$stream = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Write an article')
    ->asStream();

foreach ($stream as $chunk) {
    echo $chunk->text;
}
```

### Multi-Modal Inputs
```php
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withPrompt('Analyze this image', [Image::fromLocalPath('/path/to/image.jpg')])
    ->asText();
```

Images: `Image::fromLocalPath()`, `Image::fromStoragePath()`, `Image::fromUrl()`, `Image::fromBase64()`
Documents: `Document::fromLocalPath('file.pdf', 'Optional Title')`

### Tools
**CRITICAL**: Use `withMaxSteps(2)` or higher when using tools. Prism defaults to 1 step.

```php
$tool = Tool::as('weather')
    ->for('Get weather')
    ->withStringParameter('city', 'City name')
    ->using(fn(string $city) => "Weather in {$city}: Sunny");

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2) // Required!
    ->withPrompt('Weather in Paris?')
    ->withTools([$tool])
    ->asText();
```

### System Prompts & Messages
```php
// System prompt
Prism::text()
    ->withSystemPrompt('You are a helpful assistant')
    ->withPrompt('User prompt')
    ->asText();

// Conversation with messages
Prism::text()
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is...'),
        new UserMessage('Show example?')
    ])
    ->asText();
```

### Testing
```php
$fake = Prism::fake([
    TextResponseFake::make()->withText('Generated text')
]);

$response = Prism::text()->withPrompt('Test')->asText();

$fake->assertPrompt('Test');
$fake->assertCallCount(1);
```

### Provider-Specific Features
```php
// OpenAI reasoning models
Prism::text()
    ->using(Provider::OpenAI, 'gpt-5-mini')
    ->withProviderOptions(['reasoning' => ['effort' => 'high']])
    ->asText();

// Anthropic prompt caching (requires withMessages)
Prism::text()
    ->withMessages([
        (new SystemMessage('Long prompt...'))->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->asText();

// Anthropic thinking mode
Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withProviderOptions(['thinking' => ['enabled' => true, 'budget' => 2048]])
    ->asText();
```

### Configuration
Provider config in `config/prism.php`. Override inline:
```php
Prism::text()
    ->using(Provider::OpenAI, 'gpt-4', ['url' => 'custom-endpoint'])
    // or
    ->usingProviderConfig(['api_key' => $userKey])
    ->asText();
```

### Common Options
`withMaxTokens(2000)` - Max completion tokens
`usingTemperature(0.7)` - Creativity (0-1)
`withClientRetry(3, 1000)` - Retry config
`withClientOptions(['timeout' => 60])` - HTTP options
