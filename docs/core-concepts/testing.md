# Testing

Want to make sure your Prism integrations work flawlessly? Let's dive into testing! Prism provides a powerful fake implementation that makes it a breeze to test your AI‑powered features.

## Basic Test Setup

First, let's look at how to set up basic response faking:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\Testing\TextResponseFake;

it('can generate text', function () {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello, I am Claude!')
        ->withUsage(new Usage(10, 20));

    // Set up the fake
    $fake = Prism::fake([$fakeResponse]);

    // Run your code
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('Who are you?')
        ->asText();

    // Make assertions
    expect($response->text)->toBe('Hello, I am Claude!');
});
```

The response fakes create a new response with default values and let you fluently set the values you need for your test.

## Testing Multiple Responses

When testing conversations or tool usage, you might need to simulate multiple responses:

```php
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Meta;

it('can handle tool calls', function () {
    $responses = [
        TextResponseFake::make()
            ->withToolCalls([
                new ToolCall(
                    id: 'call_1',
                    name: 'search',
                    arguments: ['query' => 'Latest news']
                )
            ])
            ->withUsage(new Usage(15, 25))
            ->withMeta(new Meta('fake-1', 'fake-model')),

        TextResponseFake::make()
            ->withText('Here are the latest news...')
            ->withUsage(new Usage(20, 30))
            ->withMeta(new Meta('fake-2', 'fake-model')),
    ];

    $fake = Prism::fake($responses);
});
```

## Using the ResponseBuilder

If you need to test a richer response object, e.g. with Steps, you may find it easier to use the `ResponseBuilder` together with the fake Step helpers.
This is especially useful when you want to test complex streamed responses.

```php
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Messages\{UserMessage,AssistantMessage,SystemMessage};
use Prism\Prism\ValueObjects\Messages\Support\Document;

Prism::fake([
    (new ResponseBuilder)
        ->addStep(
            TextStepFake::make()
                ->withText('Step 1 response text')
                ->withFinishReason(FinishReason::Stop)
                ->withToolCalls([/* tool calls */])
                ->withToolResults([/* tool results */])
                ->withUsage(new Usage(1000, 750))
                ->withMeta(new Meta('step1', 'test-model'))
                ->withMessages([
                    new UserMessage('Test message 1', [
                        new Document(
                            document: '',
                            mimeType: 'text/plain',
                            dataFormat: 'text',
                            documentTitle: 'Test document',
                            documentContext: 'Test context'
                        ),
                    ]),
                    new AssistantMessage('Test message 2')
                ])
                ->withSystemPrompts([
                    new SystemMessage('Test system')
                ])
                ->withAdditionalContent(['test' => 'additional'])
        )
       ->addStep(
           TextStepFake::make()
                ->withText('Step 2 response text')
                ->withFinishReason(FinishReason::Stop)
                ->withToolCalls([/* tool calls */])
                ->withToolResults([/* tool results */])
                ->withUsage(new Usage(1000, 750))
                ->withMeta(new Meta(id: 123, model: 'test-model'))
                ->withMessages([/* Second step messages */])
                ->withSystemPrompts([/* Second step system prompts */])
                ->withAdditionalContent([/* Second step additional data */])
       )
        ->toResponse()
]);
```

## Testing Tools

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('can use weather tool', function () {
    // Define the expected tool call and response sequence
    $responses = [
        // First response: AI decides to use the weather tool
        TextResponseFake::make()
            ->withToolCalls([
                new ToolCall(
                    id: 'call_123',
                    name: 'weather',
                    arguments: ['city' => 'Paris']
                )
            ])
            ->withFinishReason(FinishReason::ToolCalls)
            ->withUsage(new Usage(15, 25))
            ->withMeta(new Meta('fake-1', 'fake-model')),

        // Second response: AI uses the tool result to form a response
        TextResponseFake::make()
            ->withText('Based on current conditions, the weather in Paris is sunny with a temperature of 72°F.')
            ->withToolResults([
                new ToolResult(
                    toolCallId: 'call_123',
                    toolName: 'weather',
                    args: ['city' => 'Paris'],
                    result: 'Sunny, 72°F'
                )
            ])
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(20, 30))
            ->withMeta(new Meta('fake-2', 'fake-model')),
    ];

    // Set up the fake
    $fake = Prism::fake($responses);

    // Create the weather tool
    $weatherTool = Tool::as('weather')
        ->for('Get weather information')
        ->withStringParameter('city', 'City name')
        ->using(fn (string $city) => "The weather in {$city} is sunny with a temperature of 72°F");

    // Run the actual test
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
        ->withPrompt('What\'s the weather in Paris?')
        ->withTools([$weatherTool])
        ->asText();

    // Assert the correct number of API calls were made
    $fake->assertCallCount(2);

    // Assert tool calls were made correctly
    expect($response->steps[0]->toolCalls)->toHaveCount(1);
    expect($response->steps[0]->toolCalls[0]->name)->toBe('weather');
    expect($response->steps[0]->toolCalls[0]->arguments())->toBe(['city' => 'Paris']);

    // Assert tool results were processed
    expect($response->toolResults)->toHaveCount(1);
    expect($response->toolResults[0]->result)
        ->toBe('Sunny, 72°F');

    // Assert final response
    expect($response->text)
        ->toBe('Based on current conditions, the weather in Paris is sunny with a temperature of 72°F.');
});
```

## Testing Streamed Responses

To test streamed responses, you can use any text response for a fake. The fake Provider will turn the text response into a fake stream of text chunks.
It will always finish with an empty chunk including your given finish reason.

```php
Prism::fake([
    TextResponseFake::make()
        ->withText('fake response text') // text to be streamed
        ->withFinishReason(FinishReason::Stop), // finish reason for final chunk
]);

$text = Prism::text()
    ->using('anthropic', 'claude-3-sonnet')
    ->withPrompt('What is the meaning of life?')
    ->asStream();

$outputText = '';
foreach ($text as $chunk) {
    $outputText .= $chunk->text; // will be ['fake ', 'respo', 'nse t', 'ext', '']; 
}

expect($outputText)->toBe('fake response text');
```

You can adjust the chunk size by using `withFakeChunkSize` on the fake.

```php
Prism::fake([
    TextResponseFake::make()->withText('fake response text'),
])->withFakeChunkSize(1);
```

Now, the text will be streamed in chunks of one character (`['f', 'a', 'k', ...]`).

### Testing Tool Calling while Streaming

When testing streamed responses with tool calls, you can use the `ResponseBuilder` to create a more complex response.
Given a text response with steps, the fake provider will not only generate text chunks, but also include chunks for tool calls and results.

```php
Prism::fake([
    (new ResponseBuilder)
        ->addStep(
            TextStepFake::make()
                ->withToolCalls(
                    [
                        new ToolCall('id-123', 'tool', ['input' => 'value']),
                    ]
                )
        )
        ->addStep(
            TextStepFake::make()
                ->withToolResults(
                    [
                        new ToolResult('id-123', 'tool', ['input' => 'value'], 'result'),
                    ]
                )
        )
        ->addStep(
            TextStepFake::make()
                ->withText('fake response text')
        )
        ->toResponse(),
]);

$text = Prism::text()
    ->using('anthropic', 'claude-3-sonnet')
    ->withPrompt('What is the meaning of life?')
    ->asStream();

$outputText = '';
$toolCalls = [];
$toolResults = [];

foreach ($text as $chunk) {
    $outputText .= $chunk->text;

    // Accumulate tool calls
    if ($chunk->toolCalls) {
        foreach ($chunk->toolCalls as $call) {
            $toolCalls[] = $call;
        }
    }

    // Accumulate tool results
    if ($chunk->toolResults) {
        foreach ($chunk->toolResults as $result) {
            $toolResults[] = $result;
        }
    }
}

expect($outputText)->toBe('fake response text')
    ->and($toolCalls)->toHaveCount(1)
    ->and($toolCalls[0])->toBeInstanceOf(ToolCall::class)
    ->and($toolCalls[0]->id)->toBe('id-123')
    ->and($toolCalls[0]->name)->toBe('tool')
    ->and($toolCalls[0]->arguments())->toBe(['input' => 'value'])
    ->and($toolResults)->toHaveCount(1)
    ->and($toolResults[0])->toBeInstanceOf(ToolResult::class)
    ->and($toolResults[0]->toolCallId)->toBe('id-123')
    ->and($toolResults[0]->toolName)->toBe('tool')
    ->and($toolResults[0]->args)->toBe(['input' => 'value'])
    ->and($toolResults[0]->result)->toBe('result');
```

## Testing Structured Output

```php
use Prism\Prism\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

it('can generate structured response', function () {
    $schema = new ObjectSchema(
        name: 'user',
        description: 'A user object, because we love organizing things!',
        properties: [
            new StringSchema('name', 'The user\'s name (hopefully not "test test")'),
            new StringSchema('bio', 'A brief bio (no novels, please)'),
        ],
        requiredFields: ['name', 'bio']
    );

    $fakeResponse = StructuredResponseFake::make()
        ->withText(json_encode([
            'name' => 'Alice Tester',
            'bio' => 'Professional bug hunter and code wrangler'
        ], JSON_THROW_ON_ERROR))
        ->withStructured([
            'name' => 'Alice Tester',
            'bio' => 'Professional bug hunter and code wrangler'
        ])
        ->withFinishReason(FinishReason::Stop)
        ->withUsage(new Usage(10, 20))
        ->withMeta(new Meta('fake-1', 'fake-model'));

    $fake = Prism::fake([$fakeResponse]);

    $response = Prism::structured()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('Generate a user profile')
        ->withSchema($schema)
        ->asStructured();

    // Assertions
    expect($response->structured)->toBeArray();
    expect($response->structured['name'])->toBe('Alice Tester');
    expect($response->structured['bio'])->toBe('Professional bug hunter and code wrangler');
});
```

## Testing Embeddings

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Meta;

it('can generate embeddings', function () {
    $fakeResponse = EmbeddingsResponseFake::make()
        ->withEmbeddings([Embedding::fromArray(array_fill(0, 1536, 0.1))])
        ->withUsage(new EmbeddingsUsage(10))
        ->withMeta(new Meta('fake-emb-1', 'fake-model'));

    Prism::fake([$fakeResponse]);

    $response = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-3-small')
        ->fromInput('Test content for embedding generation.')
        ->asEmbeddings();

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0]->embedding)
        ->toBeArray()
        ->toHaveCount(1536);
});
```

## Assertions

`PrismFake` provides several helpful assertion methods:

```php
// Assert specific prompt was sent
$fake->assertPrompt('Who are you?');

// Assert number of calls made
$fake->assertCallCount(2);

// Assert detailed request properties
$fake->assertRequest(function ($requests) {
    expect($requests[0]->provider())->toBe('anthropic');
    expect($requests[0]->model())->toBe('claude-3-sonnet');
});

// Assert provider configuration
$fake->assertProviderConfig(['api_key' => 'sk-1234']);
```

## Using the real response classes

While the fake helpers make tests concise, you can still build responses with the real
classes if you know you will need to test against all the properties of the response:

```php
use Prism\Prism\Text\Response;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;

$response = new Response(
    steps: collect([]),
    responseMessages: collect([]),
    text: 'The meaning of life is 42',
    finishReason: FinishReason::Stop,
    toolCalls: [],
    toolResults: [],
    usage: new Usage(42, 42),
    meta: new Meta('resp_1', 'real-model'),
    messages: collect([]),
    additionalContent: [],
);
```

This approach is perfectly valid—but for most tests the fake builders are shorter and
easier to read.