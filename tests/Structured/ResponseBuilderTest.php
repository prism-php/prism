<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('throws a PrismStructuredDecodingException if the response is not valid json', function (): void {
    $builder = new ResponseBuilder;

    $builder->addStep(new Step(
        text: 'This is not valid json',
        systemPrompts: [],
        finishReason: FinishReason::Stop,
        usage: new Usage(
            promptTokens: 0,
            completionTokens: 0
        ),
        meta: new Meta(
            id: '123',
            model: 'Test',
        ),
        messages: [],
    ));

    $builder->toResponse();
})->throws(PrismStructuredDecodingException::class);

test('StructuredResponseBuilder aggregates usage and decodes structured output', function (): void {
    $builder = new ResponseBuilder;

    // First (intermediate) step
    $builder->addStep(new Step(
        text: 'intermediate output',
        finishReason: FinishReason::Length,
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta('step1', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    // Final step that should be decoded
    $builder->addStep(new Step(
        text: '{"value":42}',
        finishReason: FinishReason::Stop,
        usage: new Usage(promptTokens: 3, completionTokens: 2),
        meta: new Meta('step2', 'test-model'),
        messages: [],
        systemPrompts: [],
    ));

    $response = $builder->toResponse();

    expect($response->usage->promptTokens)->toBe(13)
        ->and($response->usage->completionTokens)->toBe(7)
        ->and($response->structured)->toBe(['value' => 42])
        ->and($response->text)->toBe('{"value":42}')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->steps)->toHaveCount(2);
});
