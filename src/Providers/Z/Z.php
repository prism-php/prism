<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response;

final class Z extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $baseUrl,
    ) {}

    /**
     * @throws \Prism\Prism\Exceptions\PrismException
     */
    #[\Override]
    public function text(TextRequest $request): Response
    {
        $handler = new Handlers\Text(
            $this->client($request->clientOptions(), $request->clientRetry())
        );

        try {
            return $handler->handle($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Handlers\Structured(
            $this->client($request->clientOptions(), $request->clientRetry())
        );

        try {
            return $handler->handle($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $response = $e->response;
        $body = $response->json() ?? [];
        $status = $response->status();

        $message = $body['error']['message']
            ?? $body['message']
            ?? 'Unknown error from Z AI API';

        throw PrismException::providerResponseError(
            vsprintf('Z AI Error [%s]: %s', [$status, $message])
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    private function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->when($this->apiKey, fn ($client) => $client->withToken($this->apiKey))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->baseUrl);
    }
}
