<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers\Batch;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Batch\BatchResultItem;
use Prism\Prism\Providers\OpenAI\Concerns\MapsBatchResults;

class Results
{
    use MapsBatchResults;

    /**
     * @see https://www.php.net/manual/en/function.fread.php
     */
    private const STREAM_BUFFER_BYTES = 8192;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    /**
     * @return Generator<BatchResultItem>
     */
    public function handle(string $outputFileId): Generator
    {
        $response = $this->client->withOptions(['stream' => true])
            ->get("files/{$outputFileId}/content");

        $body = $response->getBody();

        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(self::STREAM_BUFFER_BYTES);

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }

                yield self::mapResultItem($decoded);
            }
        }

        $buffer = trim($buffer);
        if ($buffer !== '') {
            $decoded = json_decode($buffer, true);
            if (is_array($decoded)) {
                yield self::mapResultItem($decoded);
            }
        }
    }
}
