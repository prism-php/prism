<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Anthropic\ValueObjects\AnthropicStreamState;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Usage;

it('starts with no thinking signature', function (): void {
    $state = new AnthropicStreamState;

    expect($state->currentThinkingSignature())->toBe('');
});

it('starts with no provider tool calls', function (): void {
    $state = new AnthropicStreamState;

    expect($state->providerToolCalls())->toBe([]);
});

it('starts with no provider tool results', function (): void {
    $state = new AnthropicStreamState;

    expect($state->providerToolResults())->toBe([]);
});

it('accumulates thinking signature text across multiple appends', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('sig-');
    $state->appendThinkingSignature('abc');
    $state->appendThinkingSignature('-123');

    expect($state->currentThinkingSignature())->toBe('sig-abc-123');
});

it('supports fluent chaining when appending thinking signature', function (): void {
    $state = new AnthropicStreamState;

    $result = $state->appendThinkingSignature('test');

    expect($result)->toBe($state);
});

it('ignores empty strings when appending thinking signature', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('first');
    $state->appendThinkingSignature('');
    $state->appendThinkingSignature('second');

    expect($state->currentThinkingSignature())->toBe('firstsecond');
});

it('returns accumulated thinking signature value', function (): void {
    $state = new AnthropicStreamState;

    expect($state->currentThinkingSignature())->toBe('');

    $state->appendThinkingSignature('signature-data');

    expect($state->currentThinkingSignature())->toBe('signature-data');
});

it('tracks multiple provider tool calls by block index', function (): void {
    $state = new AnthropicStreamState;

    $providerToolCall1 = ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz789', 'name' => 'web_search'];
    $providerToolCall2 = ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz790', 'name' => 'web_fetch'];

    $state->addProviderToolCall(0, $providerToolCall1);
    $state->addProviderToolCall(1, $providerToolCall2);

    expect($state->providerToolCalls())->toBe([
        0 => $providerToolCall1,
        1 => $providerToolCall2,
    ]);
});

it('indicates no provider tool calls exist initially', function (): void {
    $state = new AnthropicStreamState;

    expect($state->hasProviderToolCalls())->toBeFalse();
});

it('indicates when provider tool calls are present', function (): void {
    $state = new AnthropicStreamState;

    $providerToolCall1 = ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz789', 'name' => 'web_search'];
    $providerToolCall2 = ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz790', 'name' => 'web_fetch'];

    $state->addProviderToolCall(0, $providerToolCall1);
    $state->addProviderToolCall(1, $providerToolCall2);

    expect($state->hasProviderToolCalls())->toBeTrue();
});

it('tracks provider tool results by block index', function (): void {
    $state = new AnthropicStreamState;

    $providerToolResult1 = ['type' => 'web_search_result', 'tool_use_id' => 'srvtoolu_xyz789', 'content' => []];

    $state->addProviderToolResult(0, $providerToolResult1);

    expect($state->providerToolResults())->toBe([
        0 => $providerToolResult1,
    ]);
});

it('indicates no provider tool results exist initially', function (): void {
    $state = new AnthropicStreamState;

    expect($state->hasProviderToolResults())->toBeFalse();
});

it('indicates when provider tool results are present', function (): void {
    $state = new AnthropicStreamState;

    $providerToolResult1 = ['type' => 'web_search_result', 'tool_use_id' => 'srvtoolu_xyz789', 'content' => []];

    $state->addProviderToolResult(0, $providerToolResult1);

    expect($state->hasProviderToolResults())->toBeTrue();
});

it('clears all state when reset', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('some-signature')
        ->addProviderToolCall(0, ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz789', 'name' => 'web_search', 'input' => ''])
        ->appendProviderToolCallInput(0, '{"query":"latest quantum computing breakthroughs 2025"}')
        ->addProviderToolResult(0, ['type' => 'web_search_result', 'tool_use_id' => 'srvtoolu_xyz789', 'content' => []])
        ->withMessageId('msg-123')
        ->appendText('text')
        ->appendThinking('thinking');
    $state->reset();

    expect($state->currentThinkingSignature())->toBe('')
        ->and($state->messageId())->toBe('')
        ->and($state->currentText())->toBe('')
        ->and($state->currentThinking())->toBe('')
        ->and($state->providerToolCalls())->toBe([])
        ->and($state->providerToolResults())->toBe([]);
});

it('supports fluent chaining when reset', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('test');

    $result = $state->reset();

    expect($result)->toBe($state)
        ->and($state->currentThinkingSignature())->toBe('');
});

it('clears text-related state while preserving stream metadata', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('signature')
        ->addProviderToolCall(0, ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz789', 'name' => 'web_search', 'input' => ''])
        ->appendProviderToolCallInput(0, '{"query":"latest quantum computing breakthroughs 2025"}')
        ->addProviderToolResult(0, ['type' => 'web_search_result', 'tool_use_id' => 'provtoolu_xyz789', 'content' => []])
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
        ->and($state->providerToolCalls())->toBe([])
        ->and($state->providerToolResults())->toBe([])
        ->and($state->model())->toBe('claude-3')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('supports fluent chaining when resetting text state', function (): void {
    $state = new AnthropicStreamState;
    $state->appendThinkingSignature('test');

    $result = $state->resetTextState();

    expect($result)->toBe($state)
        ->and($state->currentThinkingSignature())->toBe('');
});

it('supports fluent chaining across all state methods', function (): void {
    $state = new AnthropicStreamState;

    $state->withMessageId('msg-456');
    $state->withModel('claude-3-5-sonnet');
    $state->appendThinkingSignature('sig-1');
    $state->appendText('Hello');
    $state->appendThinkingSignature('-sig-2');
    $state->addProviderToolCall(0, ['id' => 'tool-123', 'input' => '']);
    $state->appendProviderToolCallInput(0, '{"query":"latest quantum computing breakthroughs 2025"}');
    $state->addProviderToolResult(0, ['type' => 'web_search_result', 'tool_use_id' => 'srvtoolu_xyz789', 'content' => []]);
    $state->markStreamStarted();

    expect($state->messageId())->toBe('msg-456')
        ->and($state->model())->toBe('claude-3-5-sonnet')
        ->and($state->currentThinkingSignature())->toBe('sig-1-sig-2')
        ->and($state->providerToolCalls())->toBe([0 => ['id' => 'tool-123', 'input' => '{"query":"latest quantum computing breakthroughs 2025"}']])
        ->and($state->currentText())->toBe('Hello')
        ->and($state->hasStreamStarted())->toBeTrue();
});

it('provides all base StreamState functionality', function (): void {
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
    $state->addProviderToolCall(0, ['type' => 'provider_tool_use', 'id' => 'provtoolu_xyz789', 'name' => 'web_search', 'input' => '']);
    $state->appendProviderToolCallInput(0, '{"query":"latest quantum computing breakthroughs 2025"}');
    $state->addProviderToolResult(0, ['type' => 'web_search_result', 'tool_use_id' => 'provtoolu_xyz789', 'content' => []]);
    $state->withBlockContext(2, 'text');
    $state->addToolCall(0, ['id' => 'tool-1', 'name' => 'search']);
    $state->addProviderToolCall(0, ['id' => 'tool-1']);
    $state->addProviderToolCall(1, ['id' => 'tool-2']);
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
        ->and($state->providerToolCalls())->toBe([0 => ['id' => 'tool-1'], 1 => ['id' => 'tool-2']])
        ->and($state->currentBlockIndex())->toBe(2)
        ->and($state->currentBlockType())->toBe('text')
        ->and($state->toolCalls())->toBe([0 => ['id' => 'tool-1', 'name' => 'search']])
        ->and($state->citations())->toBe([$citation])
        ->and($state->usage())->toBe($usage)
        ->and($state->finishReason())->toBe(FinishReason::Stop);
});

it('allows continued fluent chaining after reset', function (): void {
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

it('preserves stream and metadata when resetting text state', function (): void {
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

it('supports multiple full reset cycles', function (): void {
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

it('supports multiple text state reset cycles', function (): void {
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

it('keeps thinking signature separate from thinking content', function (): void {
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

it('preserves thinking signature through block resets', function (): void {
    $state = new AnthropicStreamState;

    $state->appendThinkingSignature('persistent-sig');
    $state->withBlockContext(5, 'text');

    $state->resetBlock();

    expect($state->currentThinkingSignature())->toBe('persistent-sig')
        ->and($state->currentBlockIndex())->toBeNull()
        ->and($state->currentBlockType())->toBeNull();
});

it('accumulates very long thinking signatures', function (): void {
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

it('clears provider tool calls when reset', function (): void {
    $state = new AnthropicStreamState;
    $state->addProviderToolCall(0, ['id' => 'tool-1']);
    $state->addProviderToolCall(1, ['id' => 'tool-2']);

    $state->reset();

    expect($state->providerToolCalls())->toBe([]);
});

it('stores provider tool calls at specific block indices', function (): void {
    $state = new AnthropicStreamState;

    $state->addProviderToolCall(0, ['id' => 'tool-1']);
    $state->addProviderToolCall(2, ['id' => 'tool-3']);

    expect($state->providerToolCalls())->toBe([
        0 => ['id' => 'tool-1'],
        2 => ['id' => 'tool-3'],
    ]);
});

it('supports fluent chaining when adding provider tool calls', function (): void {
    $state = new AnthropicStreamState;

    $result = $state->addProviderToolCall(0, ['id' => 'tool-1']);

    expect($result)->toBe($state);
});

it('accumulates provider tool call input across multiple appends', function (): void {
    $state = new AnthropicStreamState;

    $state->addProviderToolCall(0, ['input' => 'initial-']);
    $state->appendProviderToolCallInput(0, 'more-');
    $state->appendProviderToolCallInput(0, 'data');

    expect($state->providerToolCalls())->toBe([
        0 => ['input' => 'initial-more-data'],
    ]);
});

it('creates provider tool call when appending input to non-existent call', function (): void {
    $state = new AnthropicStreamState;

    $state->appendProviderToolCallInput(1, 'input-data');

    expect($state->providerToolCalls())->toBe([
        1 => ['input' => 'input-data'],
    ]);
});

it('supports fluent chaining when appending provider tool call input', function (): void {
    $state = new AnthropicStreamState;

    $result = $state->appendProviderToolCallInput(0, 'data');

    expect($result)->toBe($state);
});

it('manages multiple provider tool calls with independent inputs', function (): void {
    $state = new AnthropicStreamState;

    $state->addProviderToolCall(0, ['id' => 'tool-1', 'input' => 'start-']);
    $state->appendProviderToolCallInput(0, 'middle-');
    $state->appendProviderToolCallInput(0, 'end');

    $state->addProviderToolCall(2, ['id' => 'tool-3', 'input' => 'only-input']);

    expect($state->providerToolCalls())->toBe([
        0 => ['id' => 'tool-1', 'input' => 'start-middle-end'],
        2 => ['id' => 'tool-3', 'input' => 'only-input'],
    ]);
});
