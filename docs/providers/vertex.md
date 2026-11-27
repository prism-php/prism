# Vertex AI

Access Google's Gemini models through Vertex AI with Google Cloud authentication and regional endpoints.

## Configuration

```php
'vertex' => [
    'project_id' => env('VERTEX_PROJECT_ID', ''),
    'region' => env('VERTEX_REGION', 'us-central1'),
    'access_token' => env('VERTEX_ACCESS_TOKEN', null),
    'credentials_path' => env('VERTEX_CREDENTIALS_PATH', null),
],
```

### Authentication

Vertex AI supports multiple authentication methods:

#### 1. Access Token (Recommended for development)

Provide an access token directly:

```env
VERTEX_ACCESS_TOKEN=your-access-token
```

You can obtain an access token using the Google Cloud CLI:

```bash
gcloud auth print-access-token
```

#### 2. Service Account Credentials

Provide the path to your service account JSON key file:

```env
VERTEX_CREDENTIALS_PATH=/path/to/service-account.json
```

#### 3. Application Default Credentials

If no credentials are provided, Prism will attempt to use Application Default Credentials (ADC). Set up ADC by running:

```bash
gcloud auth application-default login
```

Or by setting the `GOOGLE_APPLICATION_CREDENTIALS` environment variable:

```bash
export GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json
```

## Basic Usage

Authentication is resolved in order: explicit access token, explicit service-account file, `GOOGLE_APPLICATION_CREDENTIALS` service-account file, then the local `gcloud` authorized-user ADC file. This implementation does not discover metadata-server credentials. Missing/unreadable files, malformed service-account keys, signing failures and token-refresh failures raise `PrismException`. Keep credential files server-side and grant only the Cloud permissions your application requires.

### Text Generation

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withPrompt('Explain quantum computing in simple terms.')
    ->asText();

echo $response->text;
```

### With System Prompt

```php
$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withSystemPrompt('You are a helpful coding assistant.')
    ->withPrompt('Write a Python function to calculate fibonacci numbers.')
    ->asText();
```

## Structured Output

Vertex AI supports structured output, allowing you to define schemas that constrain the model's responses to match your exact data structure requirements.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    name: 'user_profile',
    description: 'A user profile object',
    properties: [
        new StringSchema('name', 'The user\'s full name'),
        new NumberSchema('age', 'The user\'s age'),
        new StringSchema('email', 'The user\'s email address'),
    ],
    requiredFields: ['name', 'age', 'email']
);

$response = Prism::structured()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withSchema($schema)
    ->withPrompt('Generate a profile for a fictional user named John Doe.')
    ->asStructured();

// Access structured data
$profile = $response->structured;
echo $profile['name'];  // "John Doe"
echo $profile['age'];   // 30
echo $profile['email']; // "john.doe@example.com"
```

## Tool Usage

Vertex AI supports function calling (tools) to extend the model's capabilities:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$weatherTool = (new Tool)
    ->as('get_weather')
    ->for('Get the current weather for a location')
    ->withStringParameter('location', 'The city and state, e.g. San Francisco, CA')
    ->using(function (string $location): string {
        // Your weather API call here
        return "The weather in {$location} is 72°F and sunny.";
    });

$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather like in San Francisco?')
    ->asText();

echo $response->text;
```

## Embeddings

Vertex AI supports text embeddings for semantic search, clustering, and other ML tasks:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::Vertex, 'gemini-embedding-001')
    ->fromInput('The quick brown fox jumps over the lazy dog.')
    ->asEmbeddings();

// Access the embedding vector
$embedding = $response->embeddings[0]->embedding;
```

Vertex embeddings accept one text input per request; passing multiple inputs raises `PrismException`. Set `outputDimensionality` through provider options for a compatible model. The embedding `usage->tokens` field contains Vertex's `metadata.billableCharacterCount`, not a tokenizer count. Inspect `raw` if you need the provider's original accounting.

## Streaming

Vertex AI supports streaming responses for real-time output:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$stream = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withPrompt('Write a short story about a robot.')
    ->asStream();

foreach ($stream as $event) {
    if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
        echo $event->delta;
    }
}
```

## Image Understanding

Vertex AI supports multimodal inputs including images:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withMessages([
        new UserMessage(
            'What do you see in this image?',
            additionalContent: [
                Image::fromLocalPath('/path/to/image.png'),
            ],
        ),
    ])
    ->asText();

echo $response->text;
```

## Thinking Mode

For models that support it (like Gemini 2.5), you can configure thinking mode:

```php
$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withPrompt('Solve this complex math problem...')
    ->withProviderOptions([
        'thinkingBudget' => 2048,
    ])
    ->asText();

// Access thinking token usage
echo $response->usage->thoughtTokens;
```

## Generation options and caching

Text, structured and streaming requests accept `thinkingConfig`, `thinkingBudget`, `thinkingLevel` and `safetySettings`. Budget replaces the complete thinking configuration; level takes precedence over both. Non-streaming budget/level requests copy `includeThoughts` when supplied; streaming shorthand forces it to `true`. Use the complete `thinkingConfig` form for explicit stream thought-display controls.

Use `cachedContentName` to reference an existing resource. Alternatively, mark a stable message prefix with `withCacheHint()` and opt in with `cacheStablePrefix => true` or an integer TTL in seconds. This can create a billable cache resource. The default TTL is one hour; integer TTLs are at least 60 seconds. An explicit cached resource name takes precedence. A stable prefix without a remaining prompt does not create a resource.

For Google Search on text and structured requests, use `withProviderTools([new ProviderTool('google_search')])` with `Prism\Prism\ValueObjects\ProviderTool`. `searchGrounding => true` is a streaming shortcut only when no provider tools were supplied. Check model support for search and custom-tool combinations.

## Available Models

Vertex AI provides access to Google's Gemini model family:

| Model | Description |
|-------|-------------|
| `gemini-2.5-flash` | Fast and efficient for most tasks, with thinking support |
| `gemini-2.5-flash-lite` | Lowest latency and cost |
| `gemini-2.5-pro` | Most capable 2.5 model for complex tasks |
| `gemini-embedding-001` | Text embeddings model |

Model availability changes; check Google's [Vertex AI model list](https://cloud.google.com/vertex-ai/generative-ai/docs/models) for the current set.

## Regions

Vertex AI is available in multiple regions. Configure your region in the `.env` file:

```env
VERTEX_REGION=us-central1
```

### Regional Endpoints

Prism selects the API hostname based on the configured region:

| Region value | Endpoint hostname | Use case |
|--------------|-------------------|----------|
| `global` | `aiplatform.googleapis.com` | Global endpoint (no regional prefix) |
| `eu` | `aiplatform.eu.rep.googleapis.com` | Multi-region EU |
| `us` | `aiplatform.us.rep.googleapis.com` | Multi-region US |
| Any other value (e.g. `us-central1`) | `{region}-aiplatform.googleapis.com` | Single-region endpoint |

### Common Single-Region Values

- `us-central1` (default)
- `us-east1`
- `us-west1`
- `europe-west1`
- `europe-west4`
- `asia-northeast1`
- `asia-southeast1`

Use `global`, `eu`, or `us` when you need Google Cloud's multi-region endpoints rather than a specific single region:

```env
# Multi-region EU
VERTEX_REGION=eu

# Multi-region US
VERTEX_REGION=us

# Global endpoint
VERTEX_REGION=global
```

## Differences from Gemini API

Use the Gemini provider for API-key authentication, or Vertex for your Google Cloud project and regional endpoint. Their exposed capabilities and option mappings differ; check each guide before switching. Region selection alone does not establish a residency, compliance or SLA guarantee. Review [Vertex AI's locations and model availability](https://cloud.google.com/vertex-ai/generative-ai/docs/learn/locations) for your deployment.
