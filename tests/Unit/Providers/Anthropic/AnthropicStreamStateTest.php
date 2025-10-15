<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Anthropic\ValueObjects\AnthropicStreamState;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Usage;

it('constructs with default empty thinking signature', function (): void {
    $state = new AnthropicStreamState;

    expect($state->currentThinkingSignature())->toBe('');
});

it('appendThinkingSignature accumulates signature text', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('sig-');
    $state->appendThinkingSignature('abc');
    $state->appendThinkingSignature('-123');

    expect($state->currentThinkingSignature())->toBe('sig-abc-123');
});

it('appendThinkingSignature returns self for fluent chaining', function (): void {
    $state = new AnthropicStreamState;

    $result = $state->appendThinkingSignature('test');

    expect($result)->toBe($state);
});

it('appendThinkingSignature handles empty strings', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('first');
    $state->appendThinkingSignature('');
    $state->appendThinkingSignature('second');

    expect($state->currentThinkingSignature())->toBe('firstsecond');
});

it('currentThinkingSignature returns accumulated value', function (): void {
    $state = new AnthropicStreamState;

    expect($state->currentThinkingSignature())->toBe('');

    $state->appendThinkingSignature('signature-data');

    expect($state->currentThinkingSignature())->toBe('signature-data');
});

it('reset clears thinking signature', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('some-signature')
        ->withMessageId('msg-123')
        ->appendText('text')
        ->appendThinking('thinking');

    $state->reset();

    expect($state->currentThinkingSignature())->toBe('')
        ->and($state->messageId())->toBe('')
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('');
});

it('reset returns self', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('test');

    $result = $state->reset();

    expect($result)->toBe($state)
        ->and($state->currentThinkingSignature())->toBe('');
});

it('resetTextState clears thinking signature', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('signature')
        ->withMessageId('msg-123')
        ->appendText('text')
        ->appendThinking('thinking')
        ->withModel('claude-3')
        ->markStreamStarted();

    $state->resetTextState();

    expect($state->currentThinkingSignature())->toBe('')
        ->and($state->messageId())->toBe('')
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->model())->toBe('claude-3')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('resetTextState returns self', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('test');

    $result = $state->resetTextState();

    expect($result)->toBe($state)
        ->and($state->currentThinkingSignature())->toBe('');
});

it('supports fluent chaining with base StreamState methods', function (): void {
    $state = new AnthropicStreamState;

    $state->withMessageId('msg-456');
    $state->withModel('claude-3-5-sonnet');
    $state->appendThinkingSignature('sig-1');
    $state->appendText('Hello');
    $state->appendThinkingSignature('-sig-2');
    $state->markStreamStarted();

    expect($state->messageId())->toBe('msg-456')
        ->and($state->model())->toBe('claude-3-5-sonnet')
        ->and($state->currentThinkingSignature())->toBe('sig-1-sig-2')
        ->and($state->currentText())->toBe('Hello')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('inherits all base StreamState functionality', function (): void {
    $state = new AnthropicStreamState;
    $citation = new MessagePartWithCitations('test');
    $usage = new Usage(100, 50);

    $state->withMessageId('msg-789');
    $state->withReasoningId('reason-123');
    $state->withModel('claude-opus');
    $state->withProvider('anthropic');
    $state->withMetadata(['temperature' => 0.7]);
    $state->markStreamStarted();
    $state->markTextStarted();
    $state->markThinkingStarted();
    $state->appendText('response text');
    $state->appendThinking('thought process');
    $state->appendThinkingSignature('signature-value');
    $state->withBlockContext(2, 'text');
    $state->addToolCall(0, ['id' => 'tool-1', 'name' => 'search']);
    $state->addCitation($citation);
    $state->withUsage($usage);
    $state->withFinishReason(FinishReason::Stop);

    expect($state->messageId())->toBe('msg-789')
        ->and($state->reasoningId())->toBe('reason-123')
        ->and($state->model())->toBe('claude-opus')
        ->and($state->provider())->toBe('anthropic')
        ->and($state->metadata())->toBe(['temperature' => 0.7])
        ->and($state->hasStreamStarted())->toBeTrue()
        ->and($state->hasTextStarted())->toBeTrue()
        ->and($state->hasThinkingStarted())->toBeTrue()
        ->and($state->currentText())->toBe('response text')
        ->and($state->currentThinking())->toBe('thought process')
        ->and($state->currentThinkingSignature())->toBe('signature-value')
        ->and($state->currentBlockIndex())->toBe(2)
        ->and($state->currentBlockType())->toBe('text')
        ->and($state->toolCalls())->toBe([0 => ['id' => 'tool-1', 'name' => 'search']])
        ->and($state->citations())->toBe([$citation])
        ->and($state->usage())->toBe($usage)
        ->and($state->finishReason())->toBe(FinishReason::Stop);
});

it('reset preserves method chaining after clearing all state', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('old');
    $state->withMessageId('old-id');
    $state->appendText('old-text');

    $result = $state->reset();
    $state->appendThinkingSignature('new');
    $state->withMessageId('new-id');
    $state->appendText('new-text');

    expect($result)->toBe($state)
        ->and($state->currentThinkingSignature())->toBe('new')
        ->and($state->messageId())->toBe('new-id')
        ->and($state->currentText())->toBe('new-text');
});

it('resetTextState preserves non-text state', function (): void {
    $state = new AnthropicStreamState;
    $usage = new Usage(100, 50);

    $state->appendThinkingSignature('signature');
    $state->withMessageId('msg-123');
    $state->appendText('text');
    $state->appendThinking('thinking');
    $state->withModel('claude-3');
    $state->withProvider('anthropic');
    $state->markStreamStarted();
    $state->addToolCall(0, ['id' => 'tool']);
    $state->withUsage($usage);

    $state->resetTextState();

    expect($state->currentThinkingSignature())->toBe('')
        ->and($state->messageId())->toBe('')
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->model())->toBe('claude-3')
        ->and($state->provider())->toBe('anthropic')
        ->and($state->hasStreamStarted())->toBeTrue()
        ->and($state->toolCalls())->toBe([0 => ['id' => 'tool']])
        ->and($state->usage())->toBe($usage);
});

it('handles multiple reset cycles', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('first');
    expect($state->currentThinkingSignature())->toBe('first');

    $state->reset();
    expect($state->currentThinkingSignature())->toBe('');

    $state->appendThinkingSignature('second');
    expect($state->currentThinkingSignature())->toBe('second');

    $state->resetTextState();
    expect($state->currentThinkingSignature())->toBe('');

    $state->appendThinkingSignature('third');
    expect($state->currentThinkingSignature())->toBe('third');
});

it('handles multiple resetTextState cycles', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('sig-1')->withMessageId('msg-1');
    $state->resetTextState();
    expect($state->currentThinkingSignature())->toBe('')
        ->and($state->messageId())->toBe('');

    $state->appendThinkingSignature('sig-2')->withMessageId('msg-2');
    $state->resetTextState();
    expect($state->currentThinkingSignature())->toBe('')
        ->and($state->messageId())->toBe('');

    $state->appendThinkingSignature('sig-3')->withMessageId('msg-3');
    expect($state->currentThinkingSignature())->toBe('sig-3')
        ->and($state->messageId())->toBe('msg-3');
});

it('maintains signature independence from thinking content', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinking('thinking content');
    $state->appendThinkingSignature('signature content');

    expect($state->currentThinking())->toBe('thinking content')
        ->and($state->currentThinkingSignature())->toBe('signature content');

    $state->appendThinking(' more thinking');
    $state->appendThinkingSignature(' more signature');

    expect($state->currentThinking())->toBe('thinking content more thinking')
        ->and($state->currentThinkingSignature())->toBe('signature content more signature');
});

it('signature persists through base state resets that do not call reset', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('persistent-sig');
    $state->withBlockContext(5, 'text');

    $state->resetBlock();

    expect($state->currentThinkingSignature())->toBe('persistent-sig')
        ->and($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull();
});

it('handles very long signature accumulation', function (): void {
    $state = new AnthropicStreamState;

    for ($i = 0; $i < 100; $i++) {
        $state->appendThinkingSignature("chunk-{$i}-");
    }

    $expected = '';
    for ($i = 0; $i < 100; $i++) {
        $expected .= "chunk-{$i}-";
    }

    expect($state->currentThinkingSignature())->toBe($expected);
});
