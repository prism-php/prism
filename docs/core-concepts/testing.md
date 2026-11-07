# Testing

Use Prism's fake provider to test application behavior without making requests to an external API.

## Basic Test Setup

Configure a fake response:

```php
use Prism\Prism\Facades\Prism;
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
        ->using(Provider::Anthropic, 'claude-sonnet-5-5')
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
use Prism\Prism\ValueObjects\Media\Document;

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
                        Document::fromText('', 'Test document'),
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
                ->withMeta(new Meta(id: '123', model: 'test-model'))
                ->withMessages([/* Second step messages */])
                ->withSystemPrompts([/* Second step system prompts */])
                ->withAdditionalContent([/* Second step additional data */])
       )
        ->toResponse()
]);
```

## Testing Tools

```php
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

it('can use weather tool', function () {
    // Define the expected tool call and response sequence
    $responses = [
        (new ResponseBuilder)
            ->addStep(
                // First response: AI decides to use the weather tool
                TextStepFake::make()
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_123',
                            name: 'weather',
                            arguments: ['city' => 'Paris']
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls)
                    ->withUsage(new Usage(15, 25))
                    ->withMeta(new Meta('fake-1', 'fake-model'))
            )
            ->addStep(
                // Second response: AI uses the tool result to form a response
                TextStepFake::make()
                    ->withText('Based on current conditions, the weather in Paris is sunny with a temperature of 72°F.')
                    ->withToolResults([
                        new ToolResult(
                            toolCallId: 'call_123',
                            toolName: 'weather',
                            args: ['city' => 'Paris'],
                            result: 'Sunny, 72°F'
                        ),
                    ])
                    ->withFinishReason(FinishReason::Stop)
                    ->withUsage(new Usage(20, 30))
                    ->withMeta(new Meta('fake-2', 'fake-model')),
            )
            ->toResponse(),
    ];

    // Set up the fake
    Prism::fake($responses);

    // Create the weather tool
    $weatherTool = Tool::as('weather')
        ->for('Get weather information')
        ->withStringParameter('city', 'City name')
        ->using(fn (string $city) => "The weather in {$city} is sunny with a temperature of 72°F");

    // Run the actual test
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-sonnet-5-5')
        ->withPrompt('What\'s the weather in Paris?')
        ->withTools([$weatherTool])
        ->withMaxSteps(2)
        ->asText();

    // Assert the response has the correct number of steps
    expect($response->steps)->toHaveCount(2);

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

To test streamed responses, you can use any text response for a fake. The fake provider turns it into the same events a real provider streams: `StreamStartEvent`, `StepStartEvent`, your text as `TextStartEvent`, `TextDeltaEvent`s and `TextCompleteEvent`, then a `StepFinishEvent` and a `StreamEndEvent`. Both finish events carry your response's finish reason and usage.

```php
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Testing\TextResponseFake;

Prism::fake([
    TextResponseFake::make()
        ->withText('fake response text') // text to be streamed
        ->withFinishReason(FinishReason::Stop), // reported by StepFinishEvent and StreamEndEvent
]);

$stream = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt('What is the meaning of life?')
    ->asStream();

$outputText = '';
$lastEvent = null;

foreach ($stream as $event) {
    if ($event instanceof TextDeltaEvent) {
        $outputText .= $event->delta; // 'fake ', 'respo', 'nse t', 'ext'
    }

    $lastEvent = $event;
}

expect($outputText)->toBe('fake response text')
    ->and($lastEvent)->toBeInstanceOf(StreamEndEvent::class)
    ->and($lastEvent->finishReason)->toBe(FinishReason::Stop);
```

You can adjust the chunk size by using `withFakeChunkSize` on the fake.

```php
Prism::fake([
    TextResponseFake::make()->withText('fake response text'),
])->withFakeChunkSize(1);
```

Now, the text will be streamed in chunks of one character (`['f', 'a', 'k', ...]`).

### Testing Tool Calling while Streaming

When testing streamed responses with tool calls, use the `ResponseBuilder` to build a response with steps. The fake streams each step the way a provider does: its text, a `ToolCallEvent` for each tool call, a `ToolResultEvent` for each tool result, then a `StepFinishEvent` with that step's `finishReason`, `usage` and `additionalContent`. Every step after the first starts with its own `StepStartEvent`, and the `StreamEndEvent` reports the response's finish reason and total usage.

Give a tool step `FinishReason::ToolCalls`, just like a real provider would. `StreamedMessages::fromEvents()` leaves tool calls out of the assistant message for a step that finished any other way, because a real provider never ran them.

```php
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

Prism::fake([
    (new ResponseBuilder)
        ->addStep(
            TextStepFake::make()
                ->withToolCalls([
                    new ToolCall('id-123', 'tool', ['input' => 'value']),
                ])
                ->withToolResults([
                    new ToolResult('id-123', 'tool', ['input' => 'value'], 'result'),
                ])
                ->withFinishReason(FinishReason::ToolCalls)
                ->withUsage(new Usage(15, 25))
        )
        ->addStep(
            TextStepFake::make()
                ->withText('fake response text')
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(20, 30))
        )
        ->toResponse(),
]);

$stream = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt('What is the meaning of life?')
    ->asStream();

$outputText = '';
$toolCalls = [];
$toolResults = [];
$stepFinishes = [];

foreach ($stream as $event) {
    match (true) {
        $event instanceof TextDeltaEvent => $outputText .= $event->delta,
        $event instanceof ToolCallEvent => $toolCalls[] = $event->toolCall,
        $event instanceof ToolResultEvent => $toolResults[] = $event->toolResult,
        $event instanceof StepFinishEvent => $stepFinishes[] = $event,
        default => null,
    };
}

expect($outputText)->toBe('fake response text')
    ->and($toolCalls)->toHaveCount(1)
    ->and($toolCalls[0]->name)->toBe('tool')
    ->and($toolCalls[0]->arguments())->toBe(['input' => 'value'])
    ->and($toolResults)->toHaveCount(1)
    ->and($toolResults[0]->result)->toBe('result')
    ->and($stepFinishes)->toHaveCount(2)
    ->and($stepFinishes[0]->finishReason)->toBe(FinishReason::ToolCalls)
    ->and($stepFinishes[0]->usage->promptTokens)->toBe(15)
    ->and($stepFinishes[1]->finishReason)->toBe(FinishReason::Stop);
```

## Testing Structured Output

> [!NOTE]
> When testing OpenAI-style structured output (strict mode), the root schema should be an `ObjectSchema`.

```php
use Prism\Prism\Facades\Prism;
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
        ->using('anthropic', 'claude-sonnet-5-5')
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
use Prism\Prism\Facades\Prism;
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

## Fake scope and fixture consumption

`Prism::fake()` replaces provider behavior for text, structured output, embeddings, images and synchronous audio. It returns supplied responses without running tool handlers or performing provider request mapping. A fixture containing tool calls or results describes the response; it does not prove that the handler ran or that a provider accepts the request.

With a nonempty fixture list, each call consumes the next response. Exhausting it raises an exception; provide fixtures in operation order and with the correct response types. An empty `Prism::fake()` uses default responses instead. Streaming consumes its fixture when iterated, so consume the generator before checking recorded calls.

Moderation, FIM, Files, Batch and asynchronous audio are not implemented by this fake. Use Laravel `Http::fake()` plus `Http::preventStrayRequests()` to exercise those provider handlers, and assert the outgoing payload. See [Azure's native v1 streaming limitation](/providers/azure#streaming) before assuming Laravel fakes intercept every path.

## Assertions

`PrismFake` provides several helpful assertion methods:

> [!NOTE]
> When testing streamed responses, you must consume the stream before assertions will work. The `asStream()` method returns a generator, and the request is only recorded once the generator is iterated.
>
> ```php
> // Consume the stream before making assertions
> $chunks = collect($prism->asStream());
>
> // Now assertions will work
> $fake->assertCallCount(1);
> ```

```php
// Assert specific prompt was sent
$fake->assertPrompt('Who are you?');

// Assert number of calls made
$fake->assertCallCount(2);

// Assert detailed request properties
$fake->assertRequest(function ($requests) {
    expect($requests[0]->provider())->toBe('anthropic');
    expect($requests[0]->model())->toBe('claude-sonnet-5-5');
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
    messages: collect([]),
    text: 'The meaning of life is 42',
    finishReason: FinishReason::Stop,
    toolCalls: [],
    toolResults: [],
    usage: new Usage(42, 42),
    meta: new Meta('resp_1', 'real-model'),
    additionalContent: [],
);
```

Use real response classes when the fake builders do not expose the properties your test needs.
