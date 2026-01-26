<?php

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Support\Arr;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Enums\Provider;

class TextToSpeechRequestMapper
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

        $contents = [
            'parts' => [
                [
                    'text' => $this->request->input(),
                ],
            ],
        ];

        $baseData = [
            'model' => $this->request->model(),
            'contents' => [$contents],
        ];

        $speechConfig = $this->buildSpeechConfig($providerOptions);

        $generationConfig = Arr::whereNotNull([
            'responseModalities' => $providerOptions['responseModalities'] ?? ['AUDIO'],
            'speechConfig' => $speechConfig !== [] ? $speechConfig : null,
        ]);

        $supportedOptions = Arr::whereNotNull([
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
        ]);

        return array_merge(
            $baseData,
            $supportedOptions,
        );
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, mixed>
     */
    protected function buildSpeechConfig(array $providerOptions): array
    {
        if (isset($providerOptions['multiSpeaker']) && is_array($providerOptions['multiSpeaker'])) {
            $multiSpeakerConfig = $this->buildMultiSpeakerConfig($providerOptions['multiSpeaker']);

            if ($multiSpeakerConfig !== []) {
                return $multiSpeakerConfig;
            }
        }

        if ($this->request->voice() !== '' && $this->request->voice() !== '0') {
            return $this->buildSingleVoiceConfig($this->request->voice());
        }

        return [];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    protected function buildSingleVoiceConfig(string $voiceName): array
    {
        return [
            'voiceConfig' => [
                'prebuiltVoiceConfig' => [
                    'voiceName' => $voiceName,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{speaker?: string, voiceName?: string}>  $speakers
     * @return array<string, mixed>
     */
    protected function buildMultiSpeakerConfig(array $speakers): array
    {
        $speakerVoiceConfigs = [];

        foreach ($speakers as $speaker) {
            if (! isset($speaker['speaker'])) {
                continue;
            }
            if (! isset($speaker['voiceName'])) {
                continue;
            }
            $speakerVoiceConfigs[] = [
                'speaker' => $speaker['speaker'],
                'voiceConfig' => [
                    'prebuiltVoiceConfig' => [
                        'voiceName' => $speaker['voiceName'],
                    ],
                ],
            ];
        }

        return $speakerVoiceConfigs !== [] ? [
            'multiSpeakerVoiceConfig' => [
                'speakerVoiceConfigs' => $speakerVoiceConfigs,
            ],
        ] : [];
    }

    protected function provider(): string|Provider
    {
        return Provider::Gemini;
    }
}
