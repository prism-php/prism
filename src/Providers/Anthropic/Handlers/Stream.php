<?php
declare(strict_types=1);
namespace Prism\Prism\Providers\Anthropic\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolCallMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolChoiceMap;
use Prism\Prism\Providers\Anthropic\Maps\ToolMap;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    use CallsTools, ProcessesRateLimits;

    public function __construct(protected PendingRequest $client) {}

    /**
     * @param Request $request
     * @return Generator<Chunk>
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);
        yield from $this->processStream($response, $request);
    }

    /**
     * @param Response $response
     * @param Request $request
     * @param int $depth
     * @return Generator<Chunk>
     * @throws PrismChunkDecodeException
     * @throws PrismException
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        // Prevent infinite recursion with tool calls
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }

        $text = '';
        $toolCalls = [];
        $completedToolCalls = []; // store completed tool calls across content blocks
        $contentType = null;
        $citations = [];
        $thinking = null;
        $currentToolCallIndex = -1;

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            if ($data === null) {
                continue;
            }

            $eventType = data_get($data, 'type');

            if ($eventType === 'ping') {
                continue;
            }

            if ($eventType === 'thinking_start') {
                if ($thinking === null) {
                    $thinking = ['thinking' => ''];
                }
                continue;
            }

            if ($eventType === 'thinking') {
                if ($thinking !== null) {
                    $thinking['thinking'] .= data_get($data, 'thinking.text', '');
                }
                continue;
            }

            // Handle content block events
            if ($eventType === 'content_block_start') {
                $contentType = data_get($data, 'content_block.type');

                // Initialize tool call if this is a tool_use block
                if ($contentType === 'tool_use') {
                    $toolId = data_get($data, 'content_block.id');
                    $toolName = data_get($data, 'content_block.name');
                    $currentToolCallIndex = count($toolCalls);
                    $toolCalls[] = [
                        'id' => $toolId,
                        'name' => $toolName,
                        'arguments' => '',
                    ];
                }
                continue;
            }

            if ($eventType === 'content_block_delta') {
                if ($contentType === 'text') {
                    // Try multiple possible paths for text content
                    $content = data_get($data, 'delta.text_delta.text', '');
                    if (empty($content)) {
                        // Alternative path
                        $content = data_get($data, 'delta.text', '');
                    }
                    if (!empty($content)) {
                        $text .= $content;
                        yield new Chunk(
                            text: $content,
                            finishReason: null
                        );
                    }
                } elseif ($contentType === 'tool_use' && $currentToolCallIndex >= 0) {
                    $jsonDelta = data_get($data, 'delta.partial_json', '');

                    // Always accumulate JSON fragments, even empty ones
                    if (!isset($toolCalls[$currentToolCallIndex]['arguments'])) {
                        $toolCalls[$currentToolCallIndex]['arguments'] = '';
                    }
                    $toolCalls[$currentToolCallIndex]['arguments'] .= $jsonDelta;
                }
                continue;
            }

            if ($eventType === 'content_block_stop') {
                // If this is the end of a tool_use block, store the completed tool call
                if ($contentType === 'tool_use' && $currentToolCallIndex >= 0) {
                    $completedToolCalls[] = $toolCalls[$currentToolCallIndex];
                }

                // Reset content type after block is done
                $contentType = null;
                continue;
            }

            // Handle citation events
            if ($eventType === 'citation_start') {
                $citationId = data_get($data, 'citation.id');
                $citations[$citationId] = [
                    'id' => $citationId,
                    'start_index' => data_get($data, 'citation.start_index'),
                    'end_index' => data_get($data, 'citation.end_index'),
                    'text' => data_get($data, 'citation.text', ''),
                    'urls' => data_get($data, 'citation.urls', []),
                ];
                continue;
            }

            if ($eventType === 'message_delta') {
                $stopReason = data_get($data, 'delta.stop_reason');
                if ($stopReason === 'tool_use' && !empty($completedToolCalls)) {
                    $additionalContent = [];
                    if (!empty($citations)) {
                        $additionalContent['messagePartsWithCitations'] = $citations;
                    }
                    if ($thinking !== null) {
                        $additionalContent = array_merge($additionalContent, $thinking);
                    }

                    // First yield a chunk with tool calls
                    $toolCallObjects = ToolCallMap::map($completedToolCalls);
                    yield new Chunk(
                        text: '',
                        toolCalls: $toolCallObjects,
                        finishReason: null
                    );

                    // Then process the tool calls and continue the conversation
                    yield from $this->handleToolCalls($request, $text, $toolCallObjects, $depth, $additionalContent);
                    return;
                }
                continue;
            }

            if ($eventType === 'message_stop') {
                $stopReason = data_get($data, 'stop_reason', '');
                $finishReason = FinishReasonMap::map($stopReason);

                // Prepare additional content
                $additionalContent = [];
                if (!empty($citations)) {
                    $additionalContent['messagePartsWithCitations'] = $citations;
                }
                if ($thinking !== null) {
                    $additionalContent = array_merge($additionalContent, $thinking);
                }

                if (($stopReason === 'tool_use') && !empty($completedToolCalls)) {
                    $toolCallObjects = ToolCallMap::map($completedToolCalls);
                    yield new Chunk(
                        text: '',
                        toolCalls: $toolCallObjects,
                        finishReason: null
                    );

                    yield from $this->handleToolCalls($request, $text, $toolCallObjects, $depth, $additionalContent);
                    return;
                }

                yield new Chunk(
                    text: '',
                    finishReason: $finishReason,
                );
                return;
            }
        }

        if (!empty($completedToolCalls)) {
            $toolCallObjects = ToolCallMap::map($completedToolCalls);
            yield new Chunk(
                text: '',
                toolCalls: $toolCallObjects,
                finishReason: null
            );
        }
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     * @throws PrismChunkDecodeException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);
        $line = trim($line);

        // Skip empty lines
        if ($line === '') {
            return null;
        }

        // Check if this is an event line
        if (str_starts_with($line, 'event:')) {
            $eventType = trim(substr($line, strlen('event:')));

            // Skip ping events
            if ($eventType === 'ping') {
                return ['type' => 'ping'];
            }

            // For non-ping events, read the data line that should follow
            $dataLine = $this->readLine($stream);
            $dataLine = trim($dataLine);

            // If no data line follows or it's not a data line, just return the event type
            if (empty($dataLine) || !str_starts_with($dataLine, 'data:')) {
                return ['type' => $eventType];
            }

            // Extract and parse the JSON data
            $jsonData = trim(substr($dataLine, strlen('data:')));
            if (empty($jsonData)) {
                return ['type' => $eventType];
            }

            try {
                $data = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);

                // Ensure the event type is included
                if (!isset($data['type'])) {
                    $data['type'] = $eventType;
                }

                return $data;
            } catch (Throwable $e) {
                // If JSON parsing fails, return just the event type
                return ['type' => $eventType];
            }
        }

        // Handle standalone data lines (though these shouldn't normally occur in SSE)
        if (str_starts_with($line, 'data:')) {
            $jsonData = trim(substr($line, strlen('data:')));
            if (empty($jsonData)) {
                return null;
            }

            try {
                return json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new PrismChunkDecodeException('Anthropic', $e);
            }
        }

        // Skip any other types of lines
        return null;
    }

    /**
     * Process tool calls and continue the conversation
     *
     * @param Request $request
     * @param string $text
     * @param array<int, ToolCall> $toolCallObjects
     * @param int $depth
     * @param array|null $additionalContent
     * @return Generator<Chunk>
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    protected function handleToolCalls(
        Request $request,
        string $text,
        array $toolCallObjects,
        int $depth,
        ?array $additionalContent = null
    ): Generator {
        $toolResults = $this->callTools($request->tools(), $toolCallObjects);

        // Add messages to the request for the next API call
        $request->addMessage(new AssistantMessage(
            $text,
            $toolCallObjects,
            $additionalContent
        ));
        $request->addMessage(new ToolResultMessage($toolResults));

        // Yield tool results
        yield new Chunk(
            text: '',
            toolResults: $toolResults,
        );

        // Continue the conversation with tool results
        $nextResponse = $this->sendRequest($request);
        yield from $this->processStream($nextResponse, $request, $depth + 1);
    }

    /**
     * @throws PrismRateLimitedException
     * @throws PrismException
     */
    protected function sendRequest(Request $request): Response
    {
        try {
            $payload = array_filter([
                'stream' => true,
                'model' => $request->model(),
                'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
                'messages' => MessageMap::map($request->messages(), $request->providerMeta(Provider::Anthropic)),
                'thinking' => $request->providerMeta(Provider::Anthropic, 'thinking.enabled') === true
                    ? [
                        'type' => 'enabled',
                        'budget_tokens' => is_int($request->providerMeta(Provider::Anthropic, 'thinking.budgetTokens'))
                            ? $request->providerMeta(Provider::Anthropic, 'thinking.budgetTokens')
                            : config('prism.anthropic.default_thinking_budget', 1024),
                    ]
                    : null,
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ]);

            $apiKey = $this->client->getOptions()['headers']['Authorization'] ?? null;
            $response = $this
                ->client
                ->withOptions(['stream' => true])
                ->withHeaders([
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                    'x-api-key' => $apiKey,
                ])
                ->post('messages', $payload);

            if ($response->failed()) {
                $response->throw();
            }

            return $response;
        } catch (Throwable $e) {
            if ($e instanceof RequestException && $e->response->getStatusCode() === 429) {
                throw new PrismRateLimitedException($this->processRateLimits($e->response));
            }

            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';
        while (! $stream->eof()) {
            $byte = $stream->read(1);
            if ($byte === '') {
                return $buffer;
            }
            $buffer .= $byte;
            if ($byte === "\n") {
                break;
            }
        }
        return $buffer;
    }
}