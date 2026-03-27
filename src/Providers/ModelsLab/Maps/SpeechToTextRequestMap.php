<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Audio\SpeechToTextRequest;

class SpeechToTextRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(SpeechToTextRequest $request, string $apiKey, string $audioUrl): array
    {
        $providerOptions = $request->providerOptions();

        $baseData = [
            'key' => $apiKey,
            'init_audio' => $audioUrl,
        ];

        $supportedOptions = [
            'language' => $providerOptions['language'] ?? null,
            'timestamp_level' => $providerOptions['timestamp_level'] ?? null,
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
