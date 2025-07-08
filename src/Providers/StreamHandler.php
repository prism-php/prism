<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Psr\Http\Message\StreamInterface;

abstract class StreamHandler
{
    protected Response $httpResponse;

    protected int $step = 0;

    public function __construct(protected PendingRequest $client, protected Request $request) {}

    /**
     * @return Generator<Chunk>
     */
    public function handle(): Generator
    {
        $this->sendRequest();

        yield from $this->processStream();
    }

    /**
     * @return array<string, mixed>
     */
    abstract public static function buildHttpRequestPayload(Request $request): array;

    protected function shouldContinue(): bool
    {
        return $this->step < $this->request->maxSteps();
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

    /**
     * @return Generator<Chunk>
     */
    abstract protected function processStream(): Generator;

    abstract protected function sendRequest(): void;
}
