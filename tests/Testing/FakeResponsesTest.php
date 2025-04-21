<?php

use Illuminate\Support\Collection;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

test('fake text response can be built', function (): void {
    $response = TextResponseFake::make()
        ->withText('The meaning of life is 42')
        ->withSteps(collect([]))
        ->withResponseMessages(collect([]))
        ->withMessages(collect([]))
        ->withToolCalls([])
        ->withToolResults([])
        ->withUsage(new Usage(42, 42))
        ->withFinishReason(FinishReason::Stop)
        ->withMeta(new Meta('cpl_1234', 'claude-3-sonnet'));

    expect($response)->toBeInstanceOf(TextResponse::class)
        ->and($response->text)->toBe('The meaning of life is 42')
        ->and($response->steps)->toBeInstanceOf(Collection::class)
        ->and($response->responseMessages)->toBeInstanceOf(Collection::class)
        ->and($response->messages)->toBeInstanceOf(Collection::class)
        ->and($response->toolCalls)->toBeArray()
        ->and($response->toolResults)->toBeArray()
        ->and($response->usage)->toBeInstanceOf(Usage::class)
        ->and($response->usage->completionTokens)->toBe(42)
        ->and($response->finishReason)->toBeInstanceOf(FinishReason::class)
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->meta)->toBeInstanceOf(Meta::class)
        ->and($response->meta->model)->toBe('claude-3-sonnet')
        ->and($response->additionalContent)->toBeArray();
});

test('fake structured response can be built', function (): void {
    $response = StructuredResponseFake::make()
        ->withSteps(collect([]))
        ->withResponseMessages(collect([]))
        ->withText(json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR))
        ->withStructured(['foo' => 'bar'])
        ->withFinishReason(FinishReason::Stop)
        ->withUsage(new Usage(42, 42))
        ->withMeta(new Meta('cpl_1234', 'claude-3-sonnet'))
        ->withAdditionalContent([]);

    expect($response)->toBeInstanceOf(StructuredResponse::class)
        ->and($response->text)->toBe(json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR))
        ->and($response->steps)->toBeInstanceOf(Collection::class)
        ->and($response->responseMessages)->toBeInstanceOf(Collection::class)
        ->and($response->structured)->toBe(['foo' => 'bar'])
        ->and($response->finishReason)->toBeInstanceOf(FinishReason::class)
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage)->toBeInstanceOf(Usage::class)
        ->and($response->usage->completionTokens)->toBe(42)
        ->and($response->meta)->toBeInstanceOf(Meta::class)
        ->and($response->meta->model)->toBe('claude-3-sonnet')
        ->and($response->additionalContent)->toBeArray();
});

test('fake embeddings response can be built', function (): void {
    $response = EmbeddingsResponseFake::make()
        ->withEmbeddings([Embedding::fromArray([0.1, 0.2, 0.3])])
        ->withUsage(new EmbeddingsUsage(10))
        ->withMeta(new Meta('cpl_1234', 'claude-3-sonnet'));

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)
        ->and($response->embeddings)->toBeArray()
        ->and($response->embeddings[0])->toBeInstanceOf(Embedding::class)
        ->and($response->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3])
        ->and($response->usage)->toBeInstanceOf(EmbeddingsUsage::class)
        ->and($response->usage->tokens)->toBe(10)
        ->and($response->meta)->toBeInstanceOf(Meta::class)
        ->and($response->meta->model)->toBe('claude-3-sonnet');
});
