<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Exceptions\PrismException;

trait HandlesAsyncRequests
{
    /**
     * Poll for async result from ModelsLab API.
     *
     * @return array<string, mixed>
     *
     * @throws PrismException
     */
    protected function pollForResult(
        PendingRequest $client,
        string $fetchUrl,
        string $apiKey,
        int $maxAttempts = 60,
        int $delaySeconds = 5
    ): array {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $response = $client->post($fetchUrl, ['key' => $apiKey]);
            $data = $response->json();

            if (($data['status'] ?? '') === 'success') {
                return $data;
            }

            if (in_array($data['status'] ?? '', ['failed', 'error'], true)) {
                throw PrismException::providerResponseError(
                    $data['message'] ?? 'Generation failed'
                );
            }

            sleep($delaySeconds);
            $attempts++;
        }

        throw PrismException::providerResponseError('Request timed out waiting for ModelsLab generation');
    }
}
