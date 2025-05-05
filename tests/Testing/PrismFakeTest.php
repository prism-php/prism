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
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

describe('fake text, structured, and embedding responses', function (): void {
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

    it('fake responses using the prism fake for embeddings', function (): void {
        $fake = Prism::fake([
            new EmbeddingResponse(
                embeddings: [
                    Embedding::fromArray([
                        -0.009639355,
                        -0.00047589254,
                        -0.022748338,
                        -0.005906468,
                    ]),
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

    it('can consume the fake text responses', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('fake response text'),
        ]);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asText();

        expect($text->text)->toBe('fake response text');
    });

    it('can consume the fake structured text response', function (): void {
        Prism::fake([
            StructuredResponseFake::make()->withText('{"foo": "bar"}'),
        ]);

        $structured = Prism::structured()
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

        expect($structured->text)->toBe('{"foo": "bar"}');
    });

    it('can consume the fake embeddings response', function (): void {
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([Embedding::fromArray([0.1, 0.2, 0.3])]),
        ]);

        $embeddings = Prism::embeddings()
            ->using(Provider::OpenAI, 'text-embedding-ada-002')
            ->fromInput('What is the meaning of life?')
            ->asEmbeddings();

        expect($embeddings->embeddings)->toBeArray()
            ->and($embeddings->embeddings[0])->toBeInstanceOf(Embedding::class)
            ->and($embeddings->embeddings[0]->embedding)->toBe([0.1, 0.2, 0.3]);
    });

});

describe('fake streaming responses', function (): void {

    it('can consume the fake text stream responses', function (): void {
        Prism::fake([
            TextResponseFake::make()
                ->withText('fake response text'),
        ]);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        $toolCalls = [];
        $toolResults = [];
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;

            // Check for tool calls
            if ($chunk->toolCalls) {
                foreach ($chunk->toolCalls as $call) {
                    $toolCalls[] = $call;
                }
            }

            // Check for tool results
            if ($chunk->toolResults) {
                foreach ($chunk->toolResults as $result) {
                    $toolResults[] = $result;
                }
            }
        }

        expect($outputText)->toBe('fake response text')
            ->and($toolCalls)->toBeEmpty()
            ->and($toolResults)->toBeEmpty();
    });

    it('can consume the fake text response builder responses when streaming', function (): void {
        Prism::fake([
            (new ResponseBuilder)
                ->addStep(
                    TextStepFake::make()
                        ->withToolCalls(
                            [
                                new ToolCall('id-123', 'tool', ['input' => 'value']),
                            ]
                        )
                )
                ->addStep(
                    TextStepFake::make()
                        ->withToolResults(
                            [
                                new ToolResult('id-123', 'tool', ['input' => 'value'], 'result'),
                            ]
                        )
                )
                ->addStep(
                    TextStepFake::make()
                        ->withText('fake response text')
                )->toResponse(),
        ]);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        $toolCalls = [];
        $toolResults = [];
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;

            // Check for tool calls
            if ($chunk->toolCalls) {
                foreach ($chunk->toolCalls as $call) {
                    $toolCalls[] = $call;
                }
            }

            // Check for tool results
            if ($chunk->toolResults) {
                foreach ($chunk->toolResults as $result) {
                    $toolResults[] = $result;
                }
            }
        }

        expect($outputText)->toBe('fake response text')
            ->and($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0])->toBeInstanceOf(ToolCall::class)
            ->and($toolCalls[0]->id)->toBe('id-123')
            ->and($toolCalls[0]->name)->toBe('tool')
            ->and($toolCalls[0]->arguments())->toBe(['input' => 'value'])
            ->and($toolResults)->toHaveCount(1)
            ->and($toolResults[0])->toBeInstanceOf(ToolResult::class)
            ->and($toolResults[0]->toolCallId)->toBe('id-123')
            ->and($toolResults[0]->toolName)->toBe('tool')
            ->and($toolResults[0]->args)->toBe(['input' => 'value'])
            ->and($toolResults[0]->result)->toBe('result');
    });

    it('can consume empty text responses when streaming', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText(''),
        ]);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;
        }

        expect($outputText)->toBe('');
    });

    it('has a default chunk size of five', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('fake response text'),
        ]);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        $chunks = [];
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;
            $chunks[] = $chunk;
        }

        expect($outputText)->toBe('fake response text')
            // 19 characters -> 3 chunks of 5 + one with 3 characters + 1 empty chunk with finish reason
            ->and($chunks)->toHaveCount(5);
    });

    it('handles different chunk sizes', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('fake response text'),
        ])->withFakeChunkSize(1);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        $chunks = [];
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;
            $chunks[] = $chunk;
        }

        expect($outputText)->toBe('fake response text')
            ->and($chunks)->toHaveCount(19);
    });

    it('enforces a chunk size of at least 1', function (): void {
        Prism::fake([
            TextResponseFake::make()->withText('fake response text'),
        ])->withFakeChunkSize(0);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        $chunks = [];
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;
            $chunks[] = $chunk;
        }

        expect($outputText)->toBe('fake response text')
            ->and($chunks)->toHaveCount(19);
    });

    it('adds an empty chunk with the finish reason at the end', function (): void {
        Prism::fake([
            TextResponseFake::make()
                ->withText('fake response text')
                ->withFinishReason(FinishReason::Length),
        ]);

        $text = Prism::text()
            ->using('anthropic', 'claude-3-sonnet')
            ->withPrompt('What is the meaning of life?')
            ->asStream();

        $outputText = '';
        $lastChunk = null;
        foreach ($text as $chunk) {
            $outputText .= $chunk->text;
            $lastChunk = $chunk;
        }

        expect($outputText)->toBe('fake response text')
            ->and($lastChunk?->text)->toBe('')
            ->and($lastChunk?->finishReason)->toBe(FinishReason::Length);
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
