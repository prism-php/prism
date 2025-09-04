<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Anthropic\Actions\HandleToolCallsAction;
use Prism\Prism\Providers\Anthropic\Actions\ProcessStreamAction;
use Prism\Prism\Providers\Anthropic\Parsers\StreamEventParser;
use Prism\Prism\Providers\Anthropic\Processors\ChunkProcessor;
use Prism\Prism\Providers\Anthropic\Processors\ContentBlockProcessor;
use Prism\Prism\Providers\Anthropic\Processors\ErrorProcessor;
use Prism\Prism\Providers\Anthropic\Processors\MessageStartProcessor;
use Prism\Prism\Providers\Anthropic\Processors\MessageStopProcessor;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\ToolCall;

class Stream
{
    protected StreamState $state;

    protected ProcessStreamAction $processStreamAction;

    protected HandleToolCallsAction $handleToolCallsAction;

    public function __construct(
        protected PendingRequest $client,
        ?ProcessStreamAction $processStreamAction = null,
        ?HandleToolCallsAction $handleToolCallsAction = null
    ) {
        $this->state = new StreamState;
        $this->processStreamAction = $processStreamAction ?? $this->createProcessStreamAction();
        $this->handleToolCallsAction = $handleToolCallsAction ?? $this->createHandleToolCallsAction();
    }

    /**
     * @return Generator<Chunk>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return Generator<Chunk>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        yield from ($this->processStreamAction)($response, $request, $this->state, $depth);

        if ($this->state->hasToolCalls()) {
            $mappedToolCalls = $this->mapToolCalls();
            $additionalContent = $this->state->buildAdditionalContent();
            $text = $this->state->text();

            yield from ($this->handleToolCallsAction)($request, $mappedToolCalls, $additionalContent, $text);

            $depth++;

            if ($this->shouldContinue($request, $depth)) {
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);
            }
        }
    }

    protected function shouldContinue(Request $request, int $depth): bool
    {
        return $depth < $request->maxSteps();
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(): array
    {
        return array_values(array_map(function (array $toolCall): ToolCall {
            $input = data_get($toolCall, 'input');
            if (is_string($input) && json_validate($input)) {
                $input = json_decode($input, true);
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: $input
            );
        }, $this->state->toolCalls()));
    }

    protected function sendRequest(Request $request): Response
    {
        return $this->client
            ->withOptions(['stream' => true])
            ->post('messages', Arr::whereNotNull([
                'stream' => true,
                ...Text::buildHttpRequestPayload($request),
            ]));
    }

    protected function createProcessStreamAction(): ProcessStreamAction
    {
        return new ProcessStreamAction(
            new StreamEventParser,
            $this->createChunkProcessor()
        );
    }

    protected function createHandleToolCallsAction(): HandleToolCallsAction
    {
        return new HandleToolCallsAction;
    }

    protected function createChunkProcessor(): ChunkProcessor
    {
        return new ChunkProcessor(
            new MessageStartProcessor,
            new ContentBlockProcessor,
            new MessageStopProcessor,
            new ErrorProcessor
        );
    }
}
