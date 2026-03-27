<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Audio\TextToSpeechRequest;

class TextToSpeechRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(TextToSpeechRequest $request, string $apiKey): array
    {
        $providerOptions = $request->providerOptions();

        $baseData = [
            'key' => $apiKey,
            'prompt' => $request->input(),
            'voice_id' => $request->voice(),
        ];

        $supportedOptions = [
            'language' => $providerOptions['language'] ?? 'american english',
            'speed' => $providerOptions['speed'] ?? null,
            'emotion' => $providerOptions['emotion'] ?? null,
            'webhook' => $providerOptions['webhook'] ?? null,
            'track_id' => $providerOptions['track_id'] ?? null,
        ];

        $additionalOptions = array_diff_key($providerOptions, $supportedOptions);

        return array_merge(
            $baseData,
            Arr::whereNotNull($supportedOptions),
            $additionalOptions
        );
    }
}
