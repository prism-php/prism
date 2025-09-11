<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Actions;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Prism\Providers\Anthropic\Parsers\StreamEventParser;
use Prism\Prism\Providers\Anthropic\Processors\ChunkProcessor;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;

class ProcessStreamAction
{
    public function __construct(
        protected StreamEventParser $parser,
        protected ChunkProcessor $processor
    ) {}

    /**
     * @return Generator<Chunk>
     */
    public function __invoke(Response $response, Request $request, StreamState $state, int $depth = 0): Generator
    {
        $state->reset();

        while (! $response->getBody()->eof()) {
            $chunk = $this->parser->parseNextChunk($response->getBody());

            if ($chunk === null) {
                continue;
            }

            $outcome = $this->processor->process($chunk, $response, $request, $state, $depth);

            if ($outcome instanceof Generator) {
                yield from $outcome;
            }

            if ($outcome instanceof Chunk) {
                yield $outcome;
            }
        }
    }
}
