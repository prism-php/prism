<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Maps;

use Prism\Prism\Audio\TextToSpeechRequest;

class TextToSpeechRequestMapper
{
    public function __construct(protected TextToSpeechRequest $request) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        // TODO: Map Prism TextToSpeechRequest to ElevenLabs API format
        // Expected ElevenLabs format:
        // {
        //   "text": "string",
        //   "model_id": "string",
        //   "voice_settings": {
        //     "stability": 0.5,
        //     "similarity_boost": 0.5
        //   },
        //   "language_code": "string"
        // }

        return [
            'text' => $this->request->input(),
            // TODO: Add proper mapping for:
            // - model_id from request->model()
            // - voice_settings from request options
            // - language_code from request options
        ];
    }

    public function getVoiceId(): string
    {
        // TODO: Extract voice ID from request
        // This might come from provider options or a default voice
        return 'default-voice-id';
    }
}
