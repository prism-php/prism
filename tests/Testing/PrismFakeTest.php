<?php

declare(strict_types=1);

namespace Tests\Testing;

use Exception;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

it('fake responses using the prism fake for text', function (): void {
    $fake = Prism::fake([
        new TextResponse(
            text: 'The meaning of life is 42',
            steps: collect([]),
            responseMessages: collect([]),
            messages: collect([]),
            toolCalls: [],
            toolResults: [],
            usage: new Usage(42, 42),
            finishReason: FinishReason::Stop,
            meta: new Meta('cpl_1234', 'claude-3-sonnet')
        ),
    ]);

    Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('What is the meaning of life?')
        ->asText();

    $fake->assertCallCount(1);
    $fake->assertPrompt('What is the meaning of life?');
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1);
        expect($requests[0])->toBeInstanceOf(TextRequest::class);
    });
});

it('fake responses using the prism fake for structured', function (): void {
    $fake = Prism::fake([
        new StructuredResponse(
            steps: collect([]),
            text: json_encode(['foo' => 'bar']),
            responseMessages: collect([]),
            structured: ['foo' => 'bar'],
            usage: new Usage(42, 42),
            finishReason: FinishReason::Stop,
            meta: new Meta('cpl_1234', 'claude-3-sonnet'),
            additionalContent: [],
        ),
    ]);

    Prism::structured()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('What is the meaning of life?')
        ->withSchema(new ObjectSchema(
            'foo',
            'foo schema',
            [
                new StringSchema('foo', 'foo value'),
            ]
        ))
        ->asStructured();

    $fake->assertCallCount(1);
    $fake->assertPrompt('What is the meaning of life?');
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1);
        expect($requests[0])->toBeInstanceOf(StructuredRequest::class);
    });
});

it('fake responses using the prism fake for emeddings', function (): void {
    $fake = Prism::fake([
        new EmbeddingResponse(
            embeddings: [
                -0.009639355,
                -0.00047589254,
                -0.022748338,
                -0.005906468,
            ],
            usage: new EmbeddingsUsage(100),
            meta: new Meta(
                id: 'fake-id',
                model: 'fake-model'
            )
        ),
    ]);

    Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-ada-002')
        ->fromInput('What is the meaning of life?')
        ->asEmbeddings();

    $fake->assertCallCount(1);
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1);
        expect($requests[0])->toBeInstanceOf(EmbeddingRequest::class);
    });
});

it("throws an exception when it can't runs out of responses", function (): void {
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Could not find a response for the request');

    Prism::fake([
        new TextResponse(
            steps: collect([]),
            messages: collect([]),
            responseMessages: collect([]),
            text: 'The meaning of life is 42',
            toolCalls: [],
            toolResults: [],
            usage: new Usage(42, 42),
            finishReason: FinishReason::Stop,
            meta: new Meta('cpl_1234', 'claude-3-sonnet')
        ),
    ]);

    Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('What is the meaning of life?')
        ->asText();

    Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('What is the meaning of life?')
        ->asText();
});

it('asserts provider config', function (): void {
    $fake = Prism::fake([
        new TextResponse(
            steps: collect([]),
            messages: collect([]),
            responseMessages: collect([]),
            text: 'The meaning of life is 42',
            toolCalls: [],
            toolResults: [],
            usage: new Usage(42, 42),
            finishReason: FinishReason::Stop,
            meta: new Meta('cpl_1234', 'claude-3-sonnet')
        ),
    ]);

    Prism::text()
        ->using('anthropic', 'claude-3-sonnet')
        ->withPrompt('What is the meaning of life?')
        ->usingProviderConfig(['api_key' => '1234'])
        ->asText();

    $fake->assertProviderConfig(['api_key' => '1234']);
});
