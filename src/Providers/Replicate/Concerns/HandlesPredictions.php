<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Replicate\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\ValueObjects\ReplicatePrediction;

trait HandlesPredictions
{
    /**
     * Create a new prediction on Replicate.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RequestException
     */
    protected function createPrediction(PendingRequest $client, array $payload): ReplicatePrediction
    {
        $response = $client->post('/predictions', $payload);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return ReplicatePrediction::fromArray($response->json());
    }

    /**
     * Get the status of a prediction.
     *
     * @throws RequestException
     */
    protected function getPrediction(PendingRequest $client, string $predictionId): ReplicatePrediction
    {
        $response = $client->get("/predictions/{$predictionId}");

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return ReplicatePrediction::fromArray($response->json());
    }

    /**
     * Wait for a prediction to complete by polling.
     *
     * @throws PrismException
     */
    protected function waitForPrediction(
        PendingRequest $client,
        string $predictionId,
        int $pollingInterval = 1000,
        int $maxWaitTime = 60
    ): ReplicatePrediction {
        $startTime = time();
        $maxWaitSeconds = $maxWaitTime;

        while (true) {
            $prediction = $this->getPrediction($client, $predictionId);

            if ($prediction->isComplete()) {
                return $prediction;
            }

            if (time() - $startTime > $maxWaitSeconds) {
                throw new PrismException(
                    "Replicate: prediction timed out after {$maxWaitSeconds} seconds"
                );
            }

            // Convert milliseconds to microseconds for usleep
            usleep($pollingInterval * 1000);
        }
    }

    /**
     * Cancel a prediction.
     *
     * @throws RequestException
     */
    protected function cancelPrediction(PendingRequest $client, string $predictionId): ReplicatePrediction
    {
        $response = $client->post("/predictions/{$predictionId}/cancel");

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return ReplicatePrediction::fromArray($response->json());
    }
}
