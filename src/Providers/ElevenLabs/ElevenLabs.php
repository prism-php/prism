<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\ElevenLabs\Handlers\Audio;
use Prism\Prism\Providers\Provider;

class ElevenLabs extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url = 'https://api.elevenlabs.io/v1/',
    ) {}

    #[\Override]
    public function textToSpeech(TextToSpeechRequest $request): TextToSpeechResponse
    {
        // TODO: Implement ElevenLabs text-to-speech functionality
        $handler = new Audio($this->client());

        return $handler->handleTextToSpeech($request);
    }

    #[\Override]
    public function speechToText(SpeechToTextRequest $request): SpeechToTextResponse
    {
        $handler = new Audio($this->client());

        try {
            return $handler->handleSpeechToText($request);
        } catch (RequestException $e) {
            $this->handleRequestException($request->model(), $e);
        }
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $response = $e->response;
        $body = $response->json() ?? [];
        $status = $response->status();

        $message = $body['detail']['message'] ?? $body['detail'] ?? $body['message'] ?? 'Unknown error from ElevenLabs API';

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After') ? (int) $response->header('Retry-After') : null;
            throw PrismRateLimitedException::make([], $retryAfter);
        }

        throw PrismException::providerResponseError(
            vsprintf('ElevenLabs Error [%s]: %s', [$status, $message])
        );
    }

    protected function client(): PendingRequest
    {
        return $this->baseClient()
            ->baseUrl($this->url)
            ->withHeaders([
                'xi-api-key' => $this->apiKey,
            ]);
    }
}
