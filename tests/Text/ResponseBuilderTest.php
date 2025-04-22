<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('TextResponseBuilder aggregates usage and forwards final step text', function (): void {
    $builder = new ResponseBuilder;

    // Intermediate step
    $builder->addStep(new Step(
        text: 'hello ',
        finishReason: FinishReason::Length,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(promptTokens: 5, completionTokens: 0),
        meta: new Meta('s1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    // Final step
    $builder->addStep(new Step(
        text: 'world',
        finishReason: FinishReason::Stop,
        toolCalls: [],
        toolResults: [],
        usage: new Usage(promptTokens: 2, completionTokens: 3),
        meta: new Meta('s2', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->promptTokens)->toBe(7)
        ->and($response->usage->completionTokens)->toBe(3)
        ->and($response->text)->toBe('world')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->steps)->toHaveCount(2);
});
