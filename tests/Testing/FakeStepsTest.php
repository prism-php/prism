<?php

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Testing\StructuredStepFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\Step as TextStep;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('fake text step can be built', function (): void {
    $step = TextStepFake::make()
        ->withText('Hello world')
        ->withFinishReason(FinishReason::Stop)
        ->withToolCalls([])
        ->withToolResults([])
        ->withUsage(new Usage(1, 1))
        ->withMeta(new Meta('cpl_123', 'test-model'))
        ->withMessages([])
        ->withSystemPrompts([])
        ->withAdditionalContent([]);

    expect($step)->toBeInstanceOf(TextStep::class)
        ->and($step->text)->toBe('Hello world')
        ->and($step->finishReason)->toBe(FinishReason::Stop)
        ->and($step->toolCalls)->toBeArray()
        ->and($step->toolResults)->toBeArray()
        ->and($step->usage)->toBeInstanceOf(Usage::class)
        ->and($step->meta)->toBeInstanceOf(Meta::class)
        ->and($step->messages)->toBeArray()
        ->and($step->systemPrompts)->toBeArray()
        ->and($step->additionalContent)->toBeArray();
});

test('fake structured step can be built', function (): void {
    $step = StructuredStepFake::make()
        ->withText('{"foo":"bar"}')
        ->withFinishReason(FinishReason::Stop)
        ->withUsage(new Usage(2, 2))
        ->withMeta(new Meta('cpl_456', 'test-model'))
        ->withMessages([])
        ->withSystemPrompts([])
        ->withAdditionalContent([]);

    expect($step)->toBeInstanceOf(StructuredStep::class)
        ->and($step->text)->toBe('{"foo":"bar"}')
        ->and($step->finishReason)->toBe(FinishReason::Stop)
        ->and($step->usage)->toBeInstanceOf(Usage::class)
        ->and($step->meta)->toBeInstanceOf(Meta::class)
        ->and($step->messages)->toBeArray()
        ->and($step->systemPrompts)->toBeArray()
        ->and($step->additionalContent)->toBeArray();
});
