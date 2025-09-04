<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Processors;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;

class ChunkProcessor
{
    public function __construct(
        protected MessageStartProcessor $messageStartProcessor,
        protected ContentBlockProcessor $contentBlockProcessor,
        protected MessageStopProcessor $messageStopProcessor,
        protected ErrorProcessor $errorProcessor
    ) {}

    /**
     * @param  array<string, mixed>  $chunk
     */
    public function process(array $chunk, Response $response, Request $request, StreamState $state, int $depth): Generator|Chunk|null
    {
        return match ($chunk['type'] ?? null) {
            'message_start' => $this->messageStartProcessor->process($chunk, $response, $state),
            'content_block_start' => $this->contentBlockProcessor->processBlockStart($chunk, $state),
            'content_block_delta' => $this->contentBlockProcessor->processBlockDelta($chunk, $state),
            'content_block_stop' => $this->contentBlockProcessor->processBlockStop($state),
            'message_delta' => $this->messageStartProcessor->processDelta($chunk, $request, $state, $depth),
            'message_stop' => $this->messageStopProcessor->process($chunk, $response, $state),
            'error' => $this->errorProcessor->process($chunk),
            default => null,
        };
    }
}
