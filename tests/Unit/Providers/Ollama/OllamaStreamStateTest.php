<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Ollama\ValueObjects\OllamaStreamState;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Usage;

it('constructs with default token counts at zero', function (): void {
    $state = new OllamaStreamState;

    expect($state->promptTokens())->toBe(0)
        ->and($state->completionTokens())->toBe(0);
});

it('inherits base StreamState functionality', function (): void {
    $state = new OllamaStreamState;

    expect($state->messageId())->toBe('')
        ->and($state->model())->toBe('')
        ->and($state->provider())->toBe('')
        ->and($state->hasStreamStarted())->toBeFalse()
        ->and($state->currentText())->toBe('')
        ->and($state->toolCalls())->toBe([]);
});

it('addPromptTokens accumulates tokens', function (): void {
    $state = new OllamaStreamState;

    $state->addPromptTokens(100);
    $state->addPromptTokens(50);
    $state->addPromptTokens(25);

    expect($state->promptTokens())->toBe(175);
});

it('addPromptTokens returns self for fluent chaining', function (): void {
    $state = new OllamaStreamState;

    $result = $state->addPromptTokens(10);

    expect($result)->toBe($state);
});

it('addCompletionTokens accumulates tokens', function (): void {
    $state = new OllamaStreamState;

    $state->addCompletionTokens(200);
    $state->addCompletionTokens(75);
    $state->addCompletionTokens(30);

    expect($state->completionTokens())->toBe(305);
});

it('addCompletionTokens returns self for fluent chaining', function (): void {
    $state = new OllamaStreamState;

    $result = $state->addCompletionTokens(20);

    expect($result)->toBe($state);
});

it('promptTokens returns current count', function (): void {
    $state = new OllamaStreamState;

    expect($state->promptTokens())->toBe(0);

    $state->addPromptTokens(42);

    expect($state->promptTokens())->toBe(42);
});

it('completionTokens returns current count', function (): void {
    $state = new OllamaStreamState;

    expect($state->completionTokens())->toBe(0);

    $state->addCompletionTokens(84);

    expect($state->completionTokens())->toBe(84);
});

it('supports fluent chaining with token methods', function (): void {
    $state = new OllamaStreamState;

    $result = $state
        ->addPromptTokens(100)
        ->addCompletionTokens(50)
        ->addPromptTokens(25)
        ->addCompletionTokens(10);

    expect($result)->toBe($state)
        ->and($state->promptTokens())->toBe(125)
        ->and($state->completionTokens())->toBe(60);
});

it('supports fluent chaining with base StreamState methods', function (): void {
    $state = new OllamaStreamState;

    $result = $state
        ->withMessageId('msg-123')
        ->withModel('llama2')
        ->addPromptTokens(100)
        ->markStreamStarted()
        ->addCompletionTokens(50);

    expect($result)->toBe($state)
        ->and($state->messageId())->toBe('msg-123')
        ->and($state->model())->toBe('llama2')
        ->and($state->promptTokens())->toBe(100)
        ->and($state->completionTokens())->toBe(50)
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('reset preserves token counts for accumulation across turns', function (): void {
    $state = new OllamaStreamState;
    $state->addPromptTokens(100);
    $state->addCompletionTokens(50);

    $state->reset();

    expect($state->promptTokens())->toBe(100)
        ->and($state->completionTokens())->toBe(50);
});

it('reset preserves stream and usage state for multi-turn conversations', function (): void {
    $state = new OllamaStreamState;
    $state->withMessageId('msg-123')
        ->withModel('llama2')
        ->withProvider('ollama')
        ->markStreamStarted()
        ->appendText('some text')
        ->addToolCall(0, ['id' => 'test'])
        ->withUsage(new Usage(100, 50))
        ->addPromptTokens(200)
        ->addCompletionTokens(100);

    $state->reset();

    expect($state->messageId())->toBe('')
        ->and($state->model())->toBe('')
        ->and($state->provider())->toBe('')
        ->and($state->hasStreamStarted())->toBeTrue()
        ->and($state->currentText())->toBe('')
        ->and($state->toolCalls())->toBe([])
        ->and($state->usage())->not->toBeNull()
        ->and($state->promptTokens())->toBe(200)
        ->and($state->completionTokens())->toBe(100);
});

it('reset returns self for fluent chaining', function (): void {
    $state = new OllamaStreamState;

    $result = $state->reset();

    expect($result)->toBe($state);
});

it('handles zero token additions', function (): void {
    $state = new OllamaStreamState;

    $state->addPromptTokens(0);
    $state->addCompletionTokens(0);

    expect($state->promptTokens())->toBe(0)
        ->and($state->completionTokens())->toBe(0);
});

it('handles large token counts', function (): void {
    $state = new OllamaStreamState;

    $state->addPromptTokens(1000000);
    $state->addCompletionTokens(2000000);

    expect($state->promptTokens())->toBe(1000000)
        ->and($state->completionTokens())->toBe(2000000);
});

it('maintains independent token counters', function (): void {
    $state = new OllamaStreamState;

    $state->addPromptTokens(100);
    expect($state->promptTokens())->toBe(100);
    expect($state->completionTokens())->toBe(0);

    $state->addCompletionTokens(50);
    expect($state->promptTokens())->toBe(100);
    expect($state->completionTokens())->toBe(50);
});

it('reset accumulates tokens across resets for multi-turn usage', function (): void {
    $state = new OllamaStreamState;

    $state->addPromptTokens(100)->addCompletionTokens(50);
    expect($state->promptTokens())->toBe(100);
    expect($state->completionTokens())->toBe(50);

    $state->reset();
    expect($state->promptTokens())->toBe(100);
    expect($state->completionTokens())->toBe(50);

    $state->addPromptTokens(200)->addCompletionTokens(75);
    expect($state->promptTokens())->toBe(300);
    expect($state->completionTokens())->toBe(125);
});

it('works with base StreamState text accumulation', function (): void {
    $state = new OllamaStreamState;

    $state->appendText('Hello')
        ->addPromptTokens(5)
        ->appendText(' world')
        ->addCompletionTokens(2);

    expect($state->currentText())->toBe('Hello world')
        ->and($state->promptTokens())->toBe(5)
        ->and($state->completionTokens())->toBe(2);
});

it('works with base StreamState tool calls', function (): void {
    $state = new OllamaStreamState;

    $state->addToolCall(0, ['id' => 'call-1', 'name' => 'search'])
        ->addPromptTokens(50)
        ->addCompletionTokens(25);

    expect($state->toolCalls())->toBe([
        0 => ['id' => 'call-1', 'name' => 'search'],
    ])
        ->and($state->promptTokens())->toBe(50)
        ->and($state->completionTokens())->toBe(25);
});

it('works with base StreamState usage tracking', function (): void {
    $state = new OllamaStreamState;
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50
    );

    $state->withUsage($usage)
        ->addPromptTokens(100)
        ->addCompletionTokens(50);

    expect($state->usage())->toBe($usage)
        ->and($state->promptTokens())->toBe(100)
        ->and($state->completionTokens())->toBe(50);
});

it('works with base StreamState finish reason', function (): void {
    $state = new OllamaStreamState;

    $state->withFinishReason(FinishReason::Stop)
        ->addPromptTokens(100)
        ->addCompletionTokens(50);

    expect($state->finishReason())->toBe(FinishReason::Stop)
        ->and($state->promptTokens())->toBe(100)
        ->and($state->completionTokens())->toBe(50);
});

it('works with base StreamState citations', function (): void {
    $state = new OllamaStreamState;
    $citation = new MessagePartWithCitations('text with citation');

    $state->addCitation($citation)
        ->addPromptTokens(50)
        ->addCompletionTokens(25);

    expect($state->citations())->toBe([$citation])
        ->and($state->promptTokens())->toBe(50)
        ->and($state->completionTokens())->toBe(25);
});

it('preserves token counts when using base resetTextState', function (): void {
    $state = new OllamaStreamState;

    $state->withMessageId('msg-123')
        ->appendText('some text')
        ->addPromptTokens(100)
        ->addCompletionTokens(50);

    $state->resetTextState();

    expect($state->messageId())->toBe('')
        ->and($state->currentText())->toBe('')
        ->and($state->promptTokens())->toBe(100)
        ->and($state->completionTokens())->toBe(50);
});

it('preserves token counts when using base resetBlock', function (): void {
    $state = new OllamaStreamState;

    $state->withBlockContext(5, 'text')
        ->addPromptTokens(100)
        ->addCompletionTokens(50);

    $state->resetBlock();

    expect($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull()
        ->and($state->promptTokens())->toBe(100)
        ->and($state->completionTokens())->toBe(50);
});
