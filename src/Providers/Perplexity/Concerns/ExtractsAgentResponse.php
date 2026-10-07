<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRunException;

/**
 * Reads the Agent API's typed output array.
 *
 * The Sonar shape was `choices[]` with a `citations` array beside it. The Agent
 * API returns `output[]` — one item per step, each with a `type` — so the
 * answer and its sources are separate items rather than a field and a sibling.
 */
trait ExtractsAgentResponse
{
    use ExtractsUsage;

    /**
     * Reject unsuccessful runs despite HTTP 200. Text and Structured check the
     * response body; Stream checks the run snapshot at its terminal event.
     * Prior deltas remain provisional: no successful StreamEndEvent and no
     * GenerationCompleted are emitted, so the stream never claims success.
     * See https://github.com/Particle-Academy/prism/issues/65 for the streamed path.
     *
     * This is the trap that makes Agent API errors invisible: a failed or
     * cancelled run comes back as HTTP 200 with `status` set to "failed" or
     * "cancelled" and a populated `error`. Code that branches on the HTTP
     * status alone treats it as a success with a suspiciously empty answer.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertRunSucceeded(#[\SensitiveParameter] array $data): void
    {
        $status = data_get($data, 'status');

        if ($status === null || $status === 'completed') {
            return;
        }

        $message = data_get($data, 'error.message')
            ?? data_get($data, 'error.type')
            ?? 'no error detail was returned';

        // The 200 in this message is not a mistake — it is the finding. The
        // run failed and the transport said everything was fine.
        $errorCode = 'run_'.(is_string($status) ? $status : 'unknown');
        $exception = PrismException::providerRequestErrorWithDetails(
            provider: 'Perplexity',
            statusCode: 200,
            errorType: $errorCode,
            errorMessage: is_string($message) ? $message : 'no error detail was returned',
        );

        throw new PrismRunException(
            errorCode: $errorCode,
            message: $exception->getMessage(),
            runData: $data,
            runUsage: is_array($data['usage'] ?? null) ? $this->extractUsage($data) : null,
        );
    }

    /**
     * The answer text, concatenated across message items.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractsText(array $data): string
    {
        $text = '';

        foreach ($this->outputItemsOfType($data, 'message') as $item) {
            foreach (data_get($item, 'content') ?? [] as $content) {
                if (data_get($content, 'type') === 'output_text') {
                    $text .= (string) data_get($content, 'text', '');
                }
            }
        }

        return $text;
    }

    /**
     * Sources, kept as structured data.
     *
     * Deliberately not flattened into the answer prose. A caller repeating an
     * answer to somebody else needs to be able to resolve where it came from,
     * and that is only possible while the sources are still addressable.
     *
     * An empty list on a COMPLETED run is normal, not an error — a preset may
     * answer without searching.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function extractsSearchResults(array $data): array
    {
        $results = [];

        foreach ($this->outputItemsOfType($data, 'search_results') as $item) {
            foreach (data_get($item, 'results') ?? [] as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function extractsFetchResults(array $data): array
    {
        $results = [];

        foreach ($this->outputItemsOfType($data, 'fetch_url_results') as $item) {
            foreach (data_get($item, 'results') ?? [] as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * The text delta carried by one streamed chunk.
     *
     * The Agent API streams Responses-shaped events — a `type` naming the event
     * and a `delta` carrying the text — where Sonar streamed OpenAI chat
     * chunks. Both are read: a proxy or a recorded fixture may still speak the
     * older shape, and returning '' for a chunk we simply failed to recognise
     * would drop text with nothing to show for it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractsStreamDelta(array $data): string
    {
        $type = data_get($data, 'type');

        if (is_string($type) && str_ends_with($type, 'output_text.delta')) {
            return (string) data_get($data, 'delta', '');
        }

        return (string) data_get($data, 'choices.0.delta.content', '');
    }

    /**
     * Has this chunk ended the run?
     *
     * @param  array<string, mixed>  $data
     */
    protected function isStreamTerminal(array $data): bool
    {
        $type = data_get($data, 'type');

        if (in_array($type, ['response.completed', 'response.failed', 'response.incomplete', 'response.cancelled'], true)) {
            return true;
        }

        $status = data_get($data, 'response.status', data_get($data, 'status'));

        // Preserve an ending for future/proxy statuses, with Unknown as the
        // finish reason. Only documented progress states keep the stream open.
        // Trade-off: a new provider progress status will end the stream early
        // and discard the rest of the run. The queued/in_progress list below
        // is the only protection: add new progress statuses there when found.
        return ($status !== null && ! in_array($status, ['queued', 'in_progress'], true))
            || data_get($data, 'choices.0.finish_reason') !== null;
    }

    /**
     * Perplexity documents four response terminal events carrying run snapshots.
     * Match those names exactly: a tool's .completed event is not a run ending.
     * Flat status snapshots remain supported for recorded/proxied responses.
     *
     * @link https://github.com/perplexityai/api-platform-developers/blob/main/skills/migrate-sonar-to-agent-api/references/response-and-streaming.md
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function streamRunData(#[\SensitiveParameter] array $data): array
    {
        $run = is_array($data['response'] ?? null) ? $data['response'] : $data;
        $eventStatus = match ($data['type'] ?? null) {
            'response.completed' => 'completed',
            'response.failed' => 'failed',
            'response.incomplete' => 'incomplete',
            'response.cancelled' => 'cancelled',
            default => null,
        };

        // A failed terminal event cannot become success through a missing or
        // inconsistent snapshot status. Keep a failure reported in the snapshot
        // even if the event was labelled completed.
        if ($eventStatus !== null && ($eventStatus !== 'completed' || ! isset($run['status']))) {
            $run['status'] = $eventStatus;
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function outputItemsOfType(array $data, string $type): array
    {
        $items = data_get($data, 'output', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn ($item): bool => data_get($item, 'type') === $type,
        ));
    }
}
