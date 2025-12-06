<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Providers\ElevenLabs\Maps\TextToSpeechRequestMapper;

class Audio
{
    public function __construct(protected readonly PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        // TODO: Implement ElevenLabs text-to-speech API call
        // 1. Use TextToSpeechRequestMapper to convert request to ElevenLabs format
        // 2. Make POST request to /text-to-speech/{voice_id}
        // 3. Handle binary audio response
        // 4. Return AudioResponse with base64 encoded audio

        $mapper = new TextToSpeechRequestMapper($request);
        $mapper->toPayload();

        throw new Exception('ElevenLabs text-to-speech not yet implemented');
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = $this
            ->client
            ->attach(
                'file',
                $request->input()->resource(),
                'audio',
                ['Content-Type' => $request->input()->mimeType()]
            )
            ->post('speech-to-text', array_filter([
                'model_id' => $request->model(),
                'language_code' => $request->providerOptions('language_code'),
                'num_speakers' => $request->providerOptions('num_speakers'),
                'diarize' => $request->providerOptions('diarize'),
                'tag_audio_events' => $request->providerOptions('tag_audio_events'),
            ], fn ($value): bool => $value !== null));

        $response->throw();

        $data = $response->json();

        return new TextResponse(
            text: $data['text'] ?? '',
            additionalContent: $data,
        );
    }
}
