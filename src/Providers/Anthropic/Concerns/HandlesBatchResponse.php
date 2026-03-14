<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Prism\Prism\Batch\BatchJob;
use Prism\Prism\Batch\BatchJobRequestCounts;
use Prism\Prism\Batch\BatchStatus;
use Prism\Prism\Exceptions\PrismException;

trait HandlesBatchResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function handleResponseErrors(?array $data): void
    {
        if (data_get($data, 'type') === 'error') {
            throw PrismException::providerResponseError(vsprintf(
                'Anthropic Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message'),
                ]
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function mapBatchJob(array $data): BatchJob
    {
        return new BatchJob(
            id: data_get($data, 'id'),
            status: self::mapStatus(data_get($data, 'processing_status', '')),
            requestCounts: new BatchJobRequestCounts(
                processing: (int) data_get($data, 'request_counts.processing', 0),
                succeeded: (int) data_get($data, 'request_counts.succeeded', 0),
                failed: (int) data_get($data, 'request_counts.errored', 0),
                canceled: (int) data_get($data, 'request_counts.canceled', 0),
                expired: (int) data_get($data, 'request_counts.expired', 0),
            ),
            createdAt: data_get($data, 'created_at'),
            expiresAt: data_get($data, 'expires_at'),
            endedAt: data_get($data, 'ended_at'),
            resultsUrl: data_get($data, 'results_url'),
        );
    }

    protected static function mapStatus(string $status): BatchStatus
    {
        return match ($status) {
            'in_progress' => BatchStatus::InProgress,
            'canceling' => BatchStatus::Cancelling,
            'ended' => BatchStatus::Completed,
            default => throw new PrismException("Unknown Anthropic batch status: {$status}"),
        };
    }
}
