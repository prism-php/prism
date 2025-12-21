<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ElevenLabs\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Contracts\ProviderRequestMapper;
use Prism\Prism\Enums\Provider;

class TextToSpeechRequestMapper extends ProviderRequestMapper
{
    public function __construct(
        public readonly TextToSpeechRequest $request
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $providerOptions = $this->request->providerOptions();

        $baseData = [
            'text' => $this->request->input(),
        ];

        $voiceSettings = $providerOptions['voice_settings'] ?? [];

        $supportedOptions = [
            'model_id' => $providerOptions['model_id'] ?? null,
            'voice_settings' => [
                'stability' => $voiceSettings['stability'] ?? null,
                'use_speaker_boost' => $voiceSettings['use_speaker_boost'] ?? null,
                'similarity_boost' => $voiceSettings['similarity_boost'] ?? null,
                'style' => $voiceSettings['style'] ?? null,
                'speed' => $providerOptions['speed'] ?? null,
            ],
            'language_code' => $providerOptions['language_code'] ?? null,
            'seed' => $providerOptions['seed'] ?? null,
            'previous_text' => $providerOptions['previous_text'] ?? null,
            'next_text' => $providerOptions['next_text'] ?? null,
            'previous_request_ids' => $providerOptions['previous_request_ids'] ?? null,
            'next_request_ids' => $providerOptions['next_request_ids'] ?? null,
            'apply_text_normalization' => $providerOptions['apply_text_normalization'] ?? null,
            'apply_language_text_normalization' => $providerOptions['apply_language_text_normalization'] ?? null,
        ];

        return array_merge(
            $baseData,
            Arr::whereNotNull($supportedOptions),
            array_diff_key($providerOptions, $supportedOptions)
        );
    }

    public function getVoiceId(): string
    {
        return $this->request->voice();
    }

    protected function provider(): string|Provider
    {
        return Provider::ElevenLabs;
    }
}
