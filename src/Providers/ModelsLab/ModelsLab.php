<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\ModelsLab\Handlers\Audio;
use Prism\Prism\Providers\ModelsLab\Handlers\Images;
use Prism\Prism\Providers\ModelsLab\Handlers\Stream;
use Prism\Prism\Providers\ModelsLab\Handlers\Text;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class ModelsLab extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url = 'https://modelslab.com/api/v6/',
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->chatClient($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        try {
            return $handler->handle($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        $handler = new Images(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        try {
            return $handler->handle($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    #[\Override]
    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        $handler = new Audio(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        try {
            return $handler->handleTextToSpeech($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    #[\Override]
    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        $handler = new Audio(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        try {
            return $handler->handleSpeechToText($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream(
            $this->chatClient($request->clientOptions(), $request->clientRetry()),
            $this->apiKey
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        $response = $e->response;
        $body = $response->json() ?? [];
        $status = $response->status();

        $message = $body['message'] ?? $body['messege'] ?? $body['error'] ?? 'Unknown error from ModelsLab API';

        if ($status === 429) {
            throw PrismRateLimitedException::make([]);
        }

        throw PrismException::providerResponseError(
            vsprintf('ModelsLab Error [%s]: %s', [$status, $message])
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<int, mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($this->url);
    }

    /**
     * Client for the chat completions endpoint (uses Bearer token auth).
     *
     * @param  array<string, mixed>  $options
     * @param  array<int, mixed>  $retry
     */
    protected function chatClient(array $options = [], array $retry = []): PendingRequest
    {
        return $this->baseClient()
            ->withToken($this->apiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl('https://modelslab.com/api/');
    }
}
