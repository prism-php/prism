<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('invokes callback with collection of messages for simple text response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test prompt')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toBeInstanceOf(Collection::class);
    expect($messages)->toHaveCount(1);
});

it('callback receives assistant message with correct text content', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $messages = collect();
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('Hello World');
});

it('callback is not invoked when not set', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Hello World'),
    ]);

    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->asStream();

    expect(fn (): array => iterator_to_array($stream))->not->toThrow(Exception::class);
});

it('callback can be a closure', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test message'),
    ]);

    $callbackInvoked = false;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $messages) use (&$callbackInvoked): void {
            $callbackInvoked = true;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($callbackInvoked)->toBeTrue();
});

it('callback can be an invokable class', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Test message'),
    ]);

    $invokable = new class
    {
        public ?Collection $receivedMessages = null;

        public function __invoke(Collection $messages): void
        {
            $this->receivedMessages = $messages;
        }
    };

    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->onStreamEnd($invokable)
        ->asStream();

    iterator_to_array($stream);

    expect($invokable->receivedMessages)->toBeInstanceOf(Collection::class);
    expect($invokable->receivedMessages)->toHaveCount(1);
});

it('works with asStream method', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Stream test'),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->not->toBeNull();
    expect($messages)->toBeInstanceOf(Collection::class);
});

it('works with asEventStreamResponse method', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('SSE test'),
    ]);

    $messages = null;
    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asEventStreamResponse();

    $response->getCallback()();

    expect($messages)->not->toBeNull();
    expect($messages)->toBeInstanceOf(Collection::class);
})->skip('Outputs stream contents');

it('works with asDataStreamResponse method', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Data stream test'),
    ]);

    $messages = null;
    $response = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asDataStreamResponse();

    $response->getCallback()();

    expect($messages)->not->toBeNull();
    expect($messages)->toBeInstanceOf(Collection::class);
})->skip('Outputs stream contents');

it('assistant message contains accumulated text from multiple deltas', function (): void {
    $longText = 'This is a longer message that will be chunked into multiple deltas during streaming.';

    Prism::fake([
        TextResponseFake::make()->withText($longText),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages->first()->content)->toBe($longText);
});

it('assistant message includes tool calls when present', function (): void {
    $toolCall = new ToolCall('tool-123', 'search', ['query' => 'test']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Let me search')
                ->withToolCalls([$toolCall]),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Search something')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->toolCalls)->toHaveCount(1);
    expect($messages->first()->toolCalls[0]->name)->toBe('search');
    expect($messages->first()->toolCalls[0]->arguments())->toBe(['query' => 'test']);
});

it('tool result message is included when tools are executed', function (): void {
    $toolCall = new ToolCall('tool-1', 'calculator', ['x' => 5, 'y' => 3]);
    $toolResult = new ToolResult('tool-1', 'calculator', ['x' => 5, 'y' => 3], ['result' => 8]);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withToolCalls([$toolCall]),
            TextStepFake::make()->withToolResults([$toolResult]),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Calculate')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(2);
    expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[1]->toolResults)->toHaveCount(1);
    expect($messages[1]->toolResults[0]->result)->toBe(['result' => 8]);
});

it('multi-step conversations produce multiple assistant and tool result message pairs', function (): void {
    $toolCall1 = new ToolCall('tool-1', 'search', ['q' => 'Laravel']);
    $toolResult1 = new ToolResult('tool-1', 'search', ['q' => 'Laravel'], ['result' => 'PHP Framework']);
    $toolCall2 = new ToolCall('tool-2', 'calculate', ['x' => 10]);
    $toolResult2 = new ToolResult('tool-2', 'calculate', ['x' => 10], ['result' => 20]);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Let me search')
                ->withToolCalls([$toolCall1]),
            TextStepFake::make()->withToolResults([$toolResult1]),
            TextStepFake::make()
                ->withText('Now calculating')
                ->withToolCalls([$toolCall2]),
            TextStepFake::make()->withToolResults([$toolResult2]),
            TextStepFake::make()->withText('All done'),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Complex task')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(5);
    expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[0]->content)->toBe('Let me search');
    expect($messages[0]->toolCalls)->toHaveCount(1);
    expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[2])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[2]->content)->toBe('Now calculating');
    expect($messages[2]->toolCalls)->toHaveCount(1);
    expect($messages[3])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[4])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[4]->content)->toBe('All done');
    expect($messages[4]->toolCalls)->toBeEmpty();
});

it('handles simple text-only response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Simple response'),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Hello')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('Simple response');
    expect($messages->first()->toolCalls)->toBeEmpty();
});

it('handles response containing tool calls', function (): void {
    $toolCall1 = new ToolCall('tool-1', 'weather', ['location' => 'NYC']);
    $toolCall2 = new ToolCall('tool-2', 'time', ['timezone' => 'EST']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Checking weather and time')
                ->withToolCalls([$toolCall1, $toolCall2]),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Weather and time in NYC')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->toolCalls)->toHaveCount(2);
    expect($messages->first()->toolCalls[0]->name)->toBe('weather');
    expect($messages->first()->toolCalls[1]->name)->toBe('time');
});

it('handles multi-turn tool execution with text response', function (): void {
    $toolCall = new ToolCall('tool-1', 'database', ['query' => 'SELECT * FROM users']);
    $toolResult = new ToolResult('tool-1', 'database', ['query' => 'SELECT * FROM users'], ['count' => 42]);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Querying database')
                ->withToolCalls([$toolCall]),
            TextStepFake::make()->withToolResults([$toolResult]),
            TextStepFake::make()->withText('Found 42 users in the database'),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('How many users?')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(3);
    expect($messages[0])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[0]->content)->toBe('Querying database');
    expect($messages[1])->toBeInstanceOf(ToolResultMessage::class);
    expect($messages[2])->toBeInstanceOf(AssistantMessage::class);
    expect($messages[2]->content)->toBe('Found 42 users in the database');
});

it('handles empty response', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText(''),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toBeInstanceOf(Collection::class);
    expect($messages)->toBeEmpty();
});

it('handles response with only tool calls and no text', function (): void {
    $toolCall = new ToolCall('tool-1', 'search', ['query' => 'test']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('')
                ->withToolCalls([$toolCall]),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Search')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(AssistantMessage::class);
    expect($messages->first()->content)->toBe('');
    expect($messages->first()->toolCalls)->toHaveCount(1);
});

it('handles multiple consecutive tool results', function (): void {
    $toolResult1 = new ToolResult('tool-1', 'search', ['q' => 'a'], ['result' => 'A']);
    $toolResult2 = new ToolResult('tool-2', 'search', ['q' => 'b'], ['result' => 'B']);
    $toolResult3 = new ToolResult('tool-3', 'search', ['q' => 'c'], ['result' => 'C']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withToolResults([$toolResult1, $toolResult2, $toolResult3]),
        ])),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Multi-tool')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages)->toHaveCount(1);
    expect($messages->first())->toBeInstanceOf(ToolResultMessage::class);
    expect($messages->first()->toolResults)->toHaveCount(3);
});

it('callback receives messages collection type correctly', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Type test'),
    ]);

    $receivedType = null;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $messages) use (&$receivedType): void {
            $receivedType = $messages::class;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($receivedType)->toBe(Collection::class);
});

it('callback receives collection implementing correct interface', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Interface test'),
    ]);

    $implementsCountable = false;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $messages) use (&$implementsCountable): void {
            $implementsCountable = $messages instanceof Countable;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($implementsCountable)->toBeTrue();
});

it('callback can access all message properties', function (): void {
    $toolCall = new ToolCall('tool-1', 'test', ['key' => 'value']);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()
                ->withText('Test content')
                ->withToolCalls([$toolCall]),
        ])),
    ]);

    $contentAccessed = false;
    $toolCallsAccessed = false;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $messages) use (&$contentAccessed, &$toolCallsAccessed): void {
            /** @var AssistantMessage $message */
            $message = $messages->first();
            $contentAccessed = $message->content === 'Test content';
            $toolCallsAccessed = count($message->toolCalls) === 1;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($contentAccessed)->toBeTrue();
    expect($toolCallsAccessed)->toBeTrue();
});

it('works with unicode and special characters in messages', function (): void {
    $unicodeText = '🚀 Hello 世界! Special chars: "quotes" & \'apostrophes\'';

    Prism::fake([
        TextResponseFake::make()->withText($unicodeText),
    ]);

    $messages = null;
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Unicode test')
        ->onStreamEnd(function (Collection $responseMessages) use (&$messages): void {
            $messages = $responseMessages;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messages->first()->content)->toBe($unicodeText);
});

it('callback is invoked exactly once per stream', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('Invocation count test'),
    ]);

    $invocationCount = 0;
    $stream = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test')
        ->onStreamEnd(function (Collection $messages) use (&$invocationCount): void {
            $invocationCount++;
        })
        ->asStream();

    iterator_to_array($stream);

    expect($invocationCount)->toBe(1);
});

it('separate streams invoke callback independently', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('First stream'),
    ]);

    $firstMessages = null;
    $stream1 = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test 1')
        ->onStreamEnd(function (Collection $messages) use (&$firstMessages): void {
            $firstMessages = $messages;
        })
        ->asStream();

    iterator_to_array($stream1);

    Prism::fake([
        TextResponseFake::make()->withText('Second stream'),
    ]);

    $secondMessages = null;
    $stream2 = Prism::text()
        ->using('openai', 'gpt-4')
        ->withPrompt('Test 2')
        ->onStreamEnd(function (Collection $messages) use (&$secondMessages): void {
            $secondMessages = $messages;
        })
        ->asStream();

    iterator_to_array($stream2);

    expect($firstMessages->first()->content)->toBe('First stream');
    expect($secondMessages->first()->content)->toBe('Second stream');
});

it('collection messages maintain proper types', function (): void {
    $toolCall = new ToolCall('tool-1', 'test', []);
    $toolResult = new ToolResult('tool-1', 'test', [], []);

    Prism::fake([
        TextResponseFake::make()->withSteps(collect([
            TextStepFake::make()->withText('Step 1')->withToolCalls([$toolCall]),
            TextStepFake::make()->withToolResults([$toolResult]),
            TextStepFake::make()->withText('Step 2'),
        ])),
    ]);

    $messageTypes = [];
    $stream = Prism::text()
        ->using('anthropic', 'claude-sonnet-4-5-20250929')
        ->withPrompt('Multi-step')
        ->onStreamEnd(function (Collection $messages) use (&$messageTypes): void {
            $messageTypes = $messages->map(fn (Message $m): string => $m::class)->toArray();
        })
        ->asStream();

    iterator_to_array($stream);

    expect($messageTypes)->toBe([
        AssistantMessage::class,
        ToolResultMessage::class,
        AssistantMessage::class,
    ]);
});
