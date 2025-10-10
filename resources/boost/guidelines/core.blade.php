## Prism

### Package Overview
- Prism is a powerful Laravel package for integrating Large Language Models (LLMs) into applications with a fluent, expressive API.
- Prism supports multiple AI providers: OpenAI, Anthropic, Ollama, Mistral, Groq, XAI, Gemini, VoyageAI, ElevenLabs, DeepSeek, and OpenRouter.
- Always use the `Prism` facade, class, or `prism()` helper function for all LLM interactions.
- Prism draws inspiration from the Vercel AI SDK, adapting its concepts for the Laravel ecosystem.

### Basic Usage Patterns
- Use `Prism::text()` for text generation, `Prism::structured()` for structured output, `Prism::embeddings()` for embeddings, `Prism::image()` for image generation, and `Prism::audio()` for audio processing.
- Always chain the `using()` method to specify provider and model before generating responses.
- Use `asText()`, `asStructured()`, `asStream()`, `asEmbeddings()`, etc. to finalize the request based on the desired response type.
- You can also use the fluent `prism()` helper function as an alternative to the Prism facade.

<code-snippet name="Basic Text Generation" lang="php">
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;

// Or using the helper function
$response = prism()
    ->text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();
</code-snippet>

### Provider Configuration
- Provider configurations are stored in `config/prism.php` and typically use environment variables.
- The `Provider` enum provides type safety when specifying providers.
- Configuration can be overridden dynamically using the third parameter of `using()` or `usingProviderConfig()`.

<code-snippet name="Provider Usage with Configuration" lang="php">
// Basic provider usage
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withPrompt('Generate a product description')
    ->asText();

// Override config inline
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o', ['url' => 'custom-endpoint'])
    ->withPrompt('Generate content')
    ->asText();

// Or using usingProviderConfig()
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->usingProviderConfig(['url' => 'custom-endpoint'])
    ->withPrompt('Generate content')
    ->asText();
</code-snippet>

### Structured Output
- Use `Prism::structured()` when you need predictable, typed responses from LLMs.
- Define schemas using Prism's schema classes: `ObjectSchema`, `StringSchema`, `NumberSchema`, `ArraySchema`, `BooleanSchema`, etc.
- **IMPORTANT**: For OpenAI structured output (especially strict mode), the root schema MUST be an `ObjectSchema`. Other schema types can only be used as properties within an ObjectSchema.
- Different providers support either structured mode (strict schema validation) or JSON mode (approximate schema matching).
- Access structured data via `$response->structured` which returns a PHP array.
- Consider validating structured responses based on your application's needs.

<code-snippet name="Structured Output with Schema" lang="php">
use Prism\Prism\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

// Root schema must be ObjectSchema for OpenAI
$schema = new ObjectSchema(
    name: 'product',
    description: 'Product information',
    properties: [
        new StringSchema('name', 'Product name'),
        new StringSchema('description', 'Product description'),
        new NumberSchema('price', 'Price in dollars'),
    ],
    requiredFields: ['name', 'description', 'price']
);

$response = Prism::structured()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSchema($schema)
    ->withPrompt('Generate a product for a coffee shop')
    ->asStructured();

// Access structured data
$product = $response->structured;
if ($product !== null) {
    echo $product['name'];
}
</code-snippet>

### Streaming Responses
- Use `asStream()` for real-time response streaming, especially for long-form content generation.
- Always iterate through stream chunks and handle them appropriately for your application.
- Streaming works seamlessly with tools - you can detect tool calls and results in the stream.
- Consider using Laravel's event streaming capabilities for frontend integration.
- Be aware that Laravel Telescope may interfere with streaming - disable it if needed.

<code-snippet name="Streaming Text Generation" lang="php">
use Prism\Prism\Enums\ChunkType;

$stream = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Write a detailed article about renewable energy')
    ->asStream();

foreach ($stream as $chunk) {
    echo $chunk->text;
    // Process each chunk as it arrives
    ob_flush();
    flush();
    
    // Check for final chunk
    if ($chunk->finishReason === FinishReason::Stop) {
        echo "Generation complete!";
    }
}

// Streaming with tools
$stream = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What\'s the weather like in San Francisco?')
    ->asStream();

foreach ($stream as $chunk) {
    // Check chunk type for tool interactions
    if ($chunk->chunkType === ChunkType::ToolCall) {
        foreach ($chunk->toolCalls as $call) {
            echo "Tool called: " . $call->name;
        }
    } elseif ($chunk->chunkType === ChunkType::ToolResult) {
        foreach ($chunk->toolResults as $result) {
            echo "Tool result: " . $result->result;
        }
    } else {
        echo $chunk->text;
    }
}

// Laravel 12 Event Streams
Route::get('/chat', function () {
    return response()->eventStream(function () {
        $stream = Prism::text()
            ->using('openai', 'gpt-4')
            ->withPrompt('Explain quantum computing step by step.')
            ->asStream();

        foreach ($stream as $response) {
            yield $response->text;
        }
    });
});
</code-snippet>

### Multi-Modal Inputs
- Prism supports images, documents, audio, and video inputs alongside text prompts.
- Use appropriate value objects: `Image::fromLocalPath()`, `Document::fromLocalPath()`, `Audio::fromPath()`, etc.
- Images can be loaded from local paths, storage disks, URLs, or base64 data.
- Documents support PDF and other formats, with optional titles for better context.
- Provide descriptive text in your prompts along with media for better results.
- Check provider support tables as not all providers support all modalities.

<code-snippet name="Multi-Modal Input Example" lang="php">
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Document;

// Image from local path
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withPrompt(
        'Analyze this image and describe what you see',
        [Image::fromLocalPath('/path/to/image.jpg')]
    )
    ->asText();

// Image from storage disk
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'What is in this image?',
        [Image::fromStoragePath('images/photo.jpg', 'public')]
    )
    ->asText();

// Document analysis
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'Summarize this document',
        [Document::fromLocalPath('report.pdf', 'Quarterly Report')]
    )
    ->asText();
</code-snippet>

### Tools and Function Calling
- Use the `Tool` facade to define functions that LLMs can call during generation.
- Tools have names, descriptions, and parameters that the LLM can use.
- **IMPORTANT**: When using tools, set `withMaxSteps(2)` or higher to allow multi-step interactions.
- Prism defaults to a single step, but tools require at least 2 steps (tool call + response).
- Supports multiple parameter types: string, number, boolean, enum, array, and object parameters.

<code-snippet name="Tool Definition and Usage" lang="php">
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('weather')
    ->for('Get current weather conditions')
    ->withStringParameter('city', 'The city to get weather for')
    ->using(function (string $city): string {
        // Your weather API logic here
        return "The weather in {$city} is sunny and 72°F.";
    });

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(2) // Required for tools!
    ->withPrompt('What is the weather like in Paris?')
    ->withTools([$weatherTool])
    ->asText();

// Complex tool with multiple parameter types
$calculatorTool = Tool::as('calculator')
    ->for('Perform mathematical calculations')
    ->withStringParameter('expression', 'Mathematical expression to calculate')
    ->withBooleanParameter('round_result', 'Whether to round the result', false)
    ->using(function (string $expression, bool $roundResult = false): string {
        $result = eval("return $expression;");
        return $roundResult ? (string) round($result) : (string) $result;
    });

// Custom tool class example
class WeatherTool extends Tool
{
    public function __construct()
    {
        $this
            ->as('weather')
            ->for('Get current weather information for a city')
            ->withStringParameter('city', 'The city name to get weather for')
            ->withStringParameter('units', 'Temperature units (celsius/fahrenheit)', false)
            ->using($this);
    }

    public function __invoke(string $city, string $units = 'celsius'): string
    {
        // Your weather API implementation
        $weatherData = $this->fetchWeatherData($city, $units);
        
        return "Weather in {$city}: {$weatherData['temperature']}°" . 
               ($units === 'celsius' ? 'C' : 'F') . 
               ", {$weatherData['condition']}";
    }

    private function fetchWeatherData(string $city, string $units): array
    {
        // Implementation would call actual weather API
        return [
            'temperature' => 22,
            'condition' => 'Sunny'
        ];
    }
}

// Usage
$weatherTool = new WeatherTool();
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withMaxSteps(2)
    ->withPrompt('What\'s the weather like in London?')
    ->withTools([$weatherTool])
    ->asText();
</code-snippet>

### System Prompts and Context
- Use `withSystemPrompt()` to set behavior, persona, or context for the LLM.
- System prompts help maintain consistent behavior across interactions.
- Laravel views can be used for both system prompts and regular prompts for dynamic content.
- For conversation history, use `withMessages()` with message objects instead of single prompts.

<code-snippet name="System Prompt Usage" lang="php">
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt('You are a helpful assistant.')
    ->withPrompt('Review this code and suggest improvements')
    ->asText();

// Using Laravel views for dynamic prompts
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withSystemPrompt(view('prompts.code-review-assistant', ['language' => 'PHP']))
    ->withPrompt($codeToReview)
    ->asText();

// Views work with regular prompts too
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt(view('prompts.analysis-request', ['data' => $analysisData]))
    ->asText();

// For conversations, use messages
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format...'),
        new UserMessage('Can you show me an example?')
    ])
    ->asText();
</code-snippet>

### Testing with Prism
- Use `Prism::fake()` in tests to avoid making real API calls.
- Use response fake builders like `TextResponseFake::make()` for fluent test setup.
- Provide expected responses that match your testing needs.
- Test both successful responses and error conditions.
- Use Prism's assertion methods to verify requests, prompts, and provider configurations.

<code-snippet name="Testing with Prism Fake" lang="php">
use Prism\Prism\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

it('generates text responses', function () {
    $fakeResponse = TextResponseFake::make()
        ->withText('Generated response text')
        ->withUsage(new Usage(50, 25));

    $fake = Prism::fake([$fakeResponse]);

    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Test prompt')
        ->asText();

    expect($response->text)->toBe('Generated response text');
    
    // Assert on the request
    $fake->assertPrompt('Test prompt');
    $fake->assertCallCount(1);
    $fake->assertRequest(function ($requests) {
        expect($requests[0]->model())->toBe('gpt-4');
    });
});

// Testing multiple responses (for tool usage)
it('handles tool calls', function () {
    $responses = [
        TextResponseFake::make()->withToolCalls([/* tool calls */]),
        TextResponseFake::make()->withText('Final response after tool execution'),
    ];

    $fake = Prism::fake($responses);
    // Test your multi-step interaction
});
</code-snippet>

### Response Handling and Finish Reasons
- Always check finish reasons to understand why generation stopped.
- Handle multi-step responses when using tools by examining each step.
- Access token usage statistics for monitoring and cost management.
- Use response messages to maintain conversation history.

<code-snippet name="Complete Response Handling" lang="php">
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Explain quantum computing.')
    ->asText();

// Check why generation stopped
switch ($response->finishReason) {
    case FinishReason::Stop:
        echo "Generation completed normally";
        break;
    case FinishReason::Length:
        echo "Generation stopped due to max tokens";
        break;
    case FinishReason::ContentFilter:
        echo "Content was filtered";
        break;
    case FinishReason::ToolCalls:
        echo "Generation stopped for tool calls";
        break;
}

// Access token usage
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Completion tokens: {$response->usage->completionTokens}";

// For multi-step generations (with tools)
foreach ($response->steps as $step) {
    echo "Step text: {$step->text}";
    echo "Step tokens: {$step->usage->completionTokens}";
    
    // Check for tool calls in this step
    if ($step->toolCalls) {
        foreach ($step->toolCalls as $call) {
            echo "Called tool: {$call->name}";
        }
    }
}

// Access conversation history
foreach ($response->responseMessages as $message) {
    if ($message instanceof AssistantMessage) {
        echo "Assistant said: {$message->content}";
    }
}
</code-snippet>

### Error Handling
- Consider wrapping Prism calls in try-catch blocks based on your application's error handling strategy.
- Handle specific Prism exceptions appropriately: rate limits, API errors, validation failures.
- Implement fallback behavior when LLM calls fail as needed.

<code-snippet name="Error Handling" lang="php">
use Prism\Prism\Exceptions\PrismException;

try {
    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Generate content')
        ->asText();
        
    return $response->text;
} catch (PrismException $e) {
    // Handle Prism-specific errors
    return 'Content generation temporarily unavailable';
}
</code-snippet>

### Audio Processing
- Use `Prism::audio()` for both text-to-speech (TTS) and speech-to-text (STT) functionality.
- For TTS, specify voice and audio format options through provider-specific settings.
- For STT, provide audio files using the `Audio` value object from local paths or storage.
- Audio responses provide base64-encoded data that you can save to files or stream directly.

<code-snippet name="Audio Processing Examples" lang="php">
use Prism\Prism\ValueObjects\Media\Audio;

// Text-to-Speech
$response = Prism::audio()
    ->using(Provider::OpenAI, 'tts-1')
    ->withInput('Hello, this is a test of text-to-speech functionality.')
    ->withVoice('alloy')
    ->asAudio();

if ($response->audio->hasBase64()) {
    file_put_contents('output.mp3', base64_decode($response->audio->base64));
}

// Speech-to-Text
$audioFile = Audio::fromPath('/path/to/audio.mp3');

$response = Prism::audio()
    ->using(Provider::OpenAI, 'whisper-1')
    ->withInput($audioFile)
    ->asText();

echo $response->text; // Transcribed text
</code-snippet>

### Embeddings
- Use `Prism::embeddings()` to generate vector representations of text for semantic search and recommendations.
- Generate single or multiple embeddings in one request (except Gemini which only supports single embeddings).
- Access embeddings via `$response->embeddings[0]->embedding` which returns a float array.
- Use embeddings for similarity calculations, clustering, and semantic search implementations.

<code-snippet name="Embeddings Generation" lang="php">
// Single embedding
$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Your text to embed')
    ->asEmbeddings();

$embedding = $response->embeddings[0]->embedding; // float[]
echo "Token usage: " . $response->usage->tokens;

// Multiple embeddings at once
$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('First text')
    ->fromInput('Second text')
    ->fromArray(['Third text', 'Fourth text'])
    ->asEmbeddings();

foreach ($response->embeddings as $embedding) {
    // Process each embedding vector
    $vector = $embedding->embedding;
}
</code-snippet>

### Performance and Best Practices
- Choose appropriate models for your use case based on speed, cost, and capability requirements.
- Consider token usage and costs when designing prompts.
- Use streaming for long-running generations to improve user experience.
- Be aware that Laravel Telescope and similar packages may interfere with streaming.
- Cache responses when appropriate to avoid redundant API calls.

### Provider-Specific Features
- Take advantage of provider-specific capabilities like OpenAI's reasoning models, Anthropic's thinking modes, and prompt caching.
- Use `withProviderOptions()` to access provider-specific parameters.
- Check provider documentation for unique features and limitations.
- Some providers support reasoning/thinking tokens that show the model's thought process.

<code-snippet name="Provider-Specific Options" lang="php">
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

// OpenAI reasoning models with different effort levels
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-5-mini')
    ->withPrompt('Solve this complex problem step by step')
    ->withProviderOptions([
        'reasoning' => ['effort' => 'high'] // 'low', 'medium', 'high'
    ])
    ->asText();

// Access reasoning token usage
$usage = $response->firstStep()->usage;
echo "Reasoning tokens: " . $usage->thoughtTokens;

// Anthropic extended thinking mode
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withPrompt('Think through this complex problem carefully')
    ->withProviderOptions([
        'thinking' => ['enabled' => true, 'budget' => 2048]
    ])
    ->asText();

// Anthropic prompt caching (must use withMessages, not withPrompt)
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        (new SystemMessage('Long reusable system message...'))
            ->withProviderOptions(['cacheType' => 'ephemeral']),
        (new UserMessage('Long reusable user message...'))
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->asText();

// XAI thinking mode with streaming
$stream = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->withPrompt('Complex reasoning task')
    ->withProviderOptions(['thinking' => true])
    ->asStream();

foreach ($stream as $chunk) {
    if ($chunk->chunkType === ChunkType::Thinking) {
        echo "Thinking: " . $chunk->text;
    } else {
        echo "Answer: " . $chunk->text;
    }
}
</code-snippet>

### Prism Server
- Prism Server provides HTTP API access to your Prism functionality.
- Use the `PrismServer` facade to register named Prism configurations for HTTP access.
- Configure security with middleware and authentication as needed.
- Prism Server is disabled by default - enable via `PRISM_SERVER_ENABLED=true` environment variable.

<code-snippet name="Prism Server Configuration" lang="php">
// Register Prism configurations
use Prism\Prism\Facades\PrismServer;

PrismServer::register('chat-assistant', function () {
    return Prism::text()
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSystemPrompt('You are a helpful assistant.');
});

// In config/prism.php
'prism_server' => [
    'enabled' => env('PRISM_SERVER_ENABLED', false),
    'middleware' => [], // Configure as needed
],
</code-snippet>

### Image Generation
- Use `Prism::image()` for AI-powered image generation with supported providers.
- Configure image parameters like size, quality, and style through provider options.
- Images are returned as base64-encoded data or URLs depending on the provider.

<code-snippet name="Image Generation" lang="php">
// Basic image generation
$response = Prism::image()
    ->using(Provider::OpenAI, 'dall-e-3')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

if ($response->hasImages()) {
    $image = $response->firstImage();
    if ($image->hasUrl()) {
        echo "Image URL: " . $image->url;
    }
    if ($image->hasBase64()) {
        file_put_contents('generated.png', base64_decode($image->base64));
    }
}

// With provider-specific options
$response = Prism::image()
    ->using(Provider::OpenAI, 'dall-e-3')
    ->withPrompt('Abstract art in vibrant colors')
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'hd',
        'style' => 'vivid'
    ])
    ->generate();
</code-snippet>

### Provider Tools
- Some providers offer built-in tools like code execution, web search, and file analysis.
- Use `withProviderTools()` with `ProviderTool` objects to enable these capabilities.
- Provider tools can be combined with custom tools for powerful interactions.
- Each provider offers different built-in capabilities - check documentation for availability.

<code-snippet name="Provider Tools Usage" lang="php">
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\Facades\Tool;

// Using Anthropic's code execution tool
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withPrompt('Calculate the fibonacci sequence up to 100 and plot it')
    ->withProviderTools([
        new ProviderTool(
            type: 'code_execution_20250522',
            name: 'code_execution'
        )
    ])
    ->asText();

// Combining provider tools with custom tools
$customTool = Tool::as('database_lookup')
    ->for('Look up user information')
    ->withStringParameter('user_id', 'The user ID to look up')
    ->using(function (string $userId): string {
        return User::find($userId)->toJson();
    });

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMaxSteps(5)
    ->withPrompt('Look up user 123 and analyze their usage statistics')
    ->withTools([$customTool])
    ->withProviderTools([
        new ProviderTool(type: 'code_execution_20250522', name: 'code_execution')
    ])
    ->asText();
</code-snippet>

### Advanced Configuration Options
- Use `withClientOptions()` to configure HTTP client settings like timeouts and retries.
- Use `withMaxTokens()`, `usingTemperature()`, and `usingTopP()` to fine-tune generation parameters.
- Override provider configuration dynamically with `usingProviderConfig()` for multi-tenant apps.

<code-snippet name="Advanced Configuration" lang="php">
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Generate detailed analysis')
    ->withMaxTokens(2000)
    ->usingTemperature(0.7)
    ->withClientOptions([
        'timeout' => 60,
        'connect_timeout' => 10
    ])
    ->withClientRetry(3, 1000)
    ->usingProviderConfig([
        'api_key' => $userApiKey, // Multi-tenant API key
        'organization' => $userOrgId
    ])
    ->asText();
</code-snippet>

### Integration Options
- Prism integrates with Laravel features: queues, events, broadcasting, caching.
- Use Laravel's queue system for long-running AI tasks to avoid timeouts.
- The provider system allows switching between different AI services easily.
