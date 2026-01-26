<?php

namespace Prism\Prism\Providers\Gemini\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\ValueObjects\GeneratedAudio;

class Audio
{
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $mapper = new TextToSpeechRequestMapper($request);

        $response = $this->client->post("{$request->model()}:generateContent", $mapper->toPayload());

        if (! $response->successful()) {
            throw new Exception('Failed to generate audio: '.$response->body());
        }

        $data = $response->json();

        $base64Audio = $data['candidates'][0]['content']['parts'][0]['inlineData']['data']
            ?? throw new Exception('No audio data returned from TTS API');

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
            ),
        );
    }
}
