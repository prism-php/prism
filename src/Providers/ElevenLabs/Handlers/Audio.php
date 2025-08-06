<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Providers\ElevenLabs\Concerns\ValidatesResponse;
use Prism\Prism\Providers\ElevenLabs\Maps\TextToSpeechRequestMapper;

class Audio
{
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        // TODO: Implement ElevenLabs text-to-speech API call
        // 1. Use TextToSpeechRequestMapper to convert request to ElevenLabs format
        // 2. Make POST request to /text-to-speech/{voice_id}
        // 3. Handle binary audio response
        // 4. Return AudioResponse with base64 encoded audio

        $mapper = new TextToSpeechRequestMapper($request);
        $mapper->toPayload();

        throw new \Exception('ElevenLabs text-to-speech not yet implemented');
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        // TODO: Implement ElevenLabs speech-to-text API call
        // 1. Prepare multipart form data with audio file
        // 2. Make POST request to /speech-to-text/convert
        // 3. Handle JSON response with transcription
        // 4. Return TextResponse with transcribed text

        throw new \Exception('ElevenLabs speech-to-text not yet implemented');
    }
}
