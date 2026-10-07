<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Prism\Prism\ValueObjects\Usage;

/**
 * An unsuccessful Perplexity run, distinct from transport failure.
 * Text and Structured check the response body. Stream checks terminal snapshots;
 * Prior deltas remain provisional: no successful StreamEndEvent and no
 * GenerationCompleted are emitted, so the stream never claims success.
 * See https://github.com/Particle-Academy/prism/issues/65 for the streamed path.
 *
 * Keep provider diagnostics private and accessible only through explicit methods.
 * Copying them to public responseBody would expose partial answers to ordinary
 * JSON serialization of exceptions, including loggers, where they were absent.
 */
class PrismRunException extends PrismException
{
    /**
     * @param  array<string, mixed>  $runData
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        #[\SensitiveParameter] private readonly array $runData,
        private readonly ?Usage $runUsage,
    ) {
        parent::__construct($message, 200);
        $this->httpStatus = 200;
    }

    /** Stable identifiers include run_incomplete, run_failed and run_cancelled. */
    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): ?string
    {
        $status = $this->runData['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    public function runId(): ?string
    {
        $id = $this->runData['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function incompleteReason(): ?string
    {
        $reason = $this->incompleteDetails()['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /** @return array<string, mixed> */
    public function incompleteDetails(): array
    {
        return is_array($this->runData['incomplete_details'] ?? null) ? $this->runData['incomplete_details'] : [];
    }

    /**
     * Partial provider output, including annotations. May contain sensitive content.
     *
     * @return array<array-key, mixed>
     */
    public function output(): array
    {
        return is_array($this->runData['output'] ?? null) ? $this->runData['output'] : [];
    }

    /**
     * Inline citation annotations in provider order, without deduplication.
     * Search/fetch source items remain available in output().
     *
     * @return list<array<string, mixed>>
     */
    public function citations(): array
    {
        $citations = [];

        foreach ($this->output() as $item) {
            foreach ((array) data_get($item, 'content', []) as $content) {
                foreach ((array) data_get($content, 'annotations', []) as $annotation) {
                    if (is_array($annotation)) {
                        $citations[] = $annotation;
                    }
                }
            }
        }

        return $citations;
    }

    /** Null means the provider did not report usage, not that the run was free. */
    public function usage(): ?Usage
    {
        return $this->runUsage;
    }

    /**
     * The full provider usage ledger, including cost and tool-call breakdowns.
     *
     * @return array<string, mixed>
     */
    public function usageDetails(): array
    {
        return is_array($this->runData['usage'] ?? null) ? $this->runData['usage'] : [];
    }
}
