<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Support\Traits\Macroable;
use Prism\Prism\Audio\PendingRequest as PendingAudioRequest;
use Prism\Prism\Batch\PendingRequest as PendingBatchRequest;
use Prism\Prism\Embeddings\PendingRequest as PendingEmbeddingRequest;
use Prism\Prism\Files\PendingRequest as PendingFilesRequest;
use Prism\Prism\Fim\PendingRequest as PendingFimRequest;
use Prism\Prism\Images\PendingRequest as PendingImageRequest;
use Prism\Prism\Moderation\PendingRequest as PendingModerationRequest;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Support\ToolErrorHandler;
use Prism\Prism\Support\ToolResultClassifier;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Throwable;

class Prism
{
    use Macroable;

    /** @param Closure(ToolCall, mixed): ?string|null $classifier Host boot policy. */
    public static function classifyToolResultsUsing(?Closure $classifier): void
    {
        ToolResultClassifier::register($classifier);
    }

    /**
     * Register in your service provider's boot() in every worker process.
     * Runtime-only registration is not inherited by process-concurrency children.
     *
     * @param  (Closure(Throwable, Tool, array<int|string, mixed>): string)|null  $handler
     */
    public static function handleToolErrorsUsing(?Closure $handler): void
    {
        ToolErrorHandler::register($handler);
    }

    public function text(): PendingTextRequest
    {
        return new PendingTextRequest;
    }

    public function structured(): PendingStructuredRequest
    {
        return new PendingStructuredRequest;
    }

    public function embeddings(): PendingEmbeddingRequest
    {
        return new PendingEmbeddingRequest;
    }

    public function image(): PendingImageRequest
    {
        return new PendingImageRequest;
    }

    public function audio(): PendingAudioRequest
    {
        return new PendingAudioRequest;
    }

    public function moderation(): PendingModerationRequest
    {
        return new PendingModerationRequest;
    }

    public function files(): PendingFilesRequest
    {
        return new PendingFilesRequest;
    }

    public function batch(): PendingBatchRequest
    {
        return new PendingBatchRequest;
    }

    public function fim(): PendingFimRequest
    {
        return new PendingFimRequest;
    }
}
