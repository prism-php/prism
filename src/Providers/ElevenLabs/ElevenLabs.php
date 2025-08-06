<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\AudioResponse as TextToSpeechResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SpeechToTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\InitializesClient;
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
        // TODO: Implement ElevenLabs speech-to-text functionality
        $handler = new Audio($this->client());

        return $handler->handleSpeechToText($request);
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
