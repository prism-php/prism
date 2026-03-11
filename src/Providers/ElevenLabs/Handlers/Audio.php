<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\GeneratesAudioFilename;
use Prism\Prism\Providers\ElevenLabs\Maps\TextToSpeechRequestMapper;
use Prism\Prism\ValueObjects\GeneratedAudio;

class Audio
{
    use GeneratesAudioFilename;

    public function __construct(protected readonly PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $mapper = new TextToSpeechRequestMapper($request);

        $response = $this->client->post('text-to-speech/'.$request->voice(), $mapper->toPayload());

        if (! $response->successful()) {
            throw new Exception('Failed to generate audio: '.$response->body());
        }

        $audioContent = $response->body();
        $base64Audio = base64_encode($audioContent);

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
            ),
        );
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->attach(
                'file',
                $request->input()->resource(),
                $this->generateFilename($request->input()->mimeType()),
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
