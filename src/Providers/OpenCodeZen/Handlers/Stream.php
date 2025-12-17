<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Providers\OpenCodeZen\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenCodeZen\Concerns\MapsFinishReason;
use Prism\Prism\Providers\OpenCodeZen\Concerns\ValidatesResponses;
use Prism\Prism\Providers\OpenCodeZen\Maps\MessageMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Stream
{
    use BuildsRequestOptions;
    use CallsTools;
    use MapsFinishReason;
    use ValidatesResponses;

    protected string $content = '';

    protected array $toolCalls = [];

    protected bool $hasStarted = false;

    public function __construct(protected PendingRequest $client) {}

    /**
     * @return Generator<int, StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        /** @var Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
                'stream' => true,
            ], $this->buildRequestOptions($request))
        );

        if ($response->failed()) {
            $this->validateResponse($response->json());
        }

        yield from $this->processStream($request, $response->getBody());
    }

    /**
     * @return Generator<int, StreamEvent>
     */
    protected function processStream(Request $request, $stream): Generator
    {
        $buffer = '';

        foreach ($this->readStream($stream) as $line) {
            $buffer .= $line;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $chunk = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (Str::startsWith($chunk, 'data: ')) {
                    $data = trim(substr($chunk, 6));

                    if ($data === '[DONE]') {
                        return;
                    }

                    if ($data !== '') {
                        try {
                            $decodedData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                            $event = $this->mapToStreamEvent($decodedData);

                            if ($event instanceof \Prism\Prism\Streaming\Events\StreamEvent) {
                                yield $event;
                            }

                            if ($event instanceof StreamEndEvent) {
                                return;
                            }
                        } catch (Throwable $e) {
                            throw new PrismStreamDecodeException(
                                sprintf('Stream decoding failed: %s', $e->getMessage()),
                                previous: $e
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * @return Generator<string>
     */
    protected function readStream($stream): Generator
    {
        while (! feof($stream)) {
            yield fread($stream, 4096);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapToStreamEvent(array $data): ?StreamEvent
    {
        $content = Arr::get($data, 'choices.0.delta.content', '');
        $finishReason = Arr::get($data, 'choices.0.finish_reason');

        if ($content !== '') {
            $this->content .= $content;

            if (! $this->hasStarted) {
                $this->hasStarted = true;

                return new TextStartEvent(
                    id: EventID::generate(),
                    content: $this->content,
                    model: Arr::get($data, 'model'),
                );
            }

            return new TextDeltaEvent(
                id: EventID::generate(),
                content: $content,
            );
        }

        if ($finishReason !== null) {
            return new StreamEndEvent(
                finishReason: $this->mapFinishReason($data),
                usage: new Usage(
                    promptTokens: Arr::get($data, 'usage.prompt_tokens'),
                    completionTokens: Arr::get($data, 'usage.completion_tokens'),
                ),
                meta: [
                    'id' => Arr::get($data, 'id'),
                    'model' => Arr::get($data, 'model'),
                ],
                response: new AssistantMessage(
                    content: $this->content,
                    toolCalls: $this->toolCalls,
                ),
            );
        }

        return null;
    }
}
