<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;

class ImageRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(Request $request): array
    {
        $baseData = [
            'model' => $request->model(),
            'prompt' => $request->prompt(),
        ];

        $providerOptions = $request->providerOptions();

        // Explicitly handle known OpenAI image generation parameters
        $supportedOptions = [
            // Common parameters across all models
            'n' => $providerOptions['n'] ?? null,
            'size' => $providerOptions['size'] ?? null,
            'response_format' => $providerOptions['response_format'] ?? null,
            'user' => $providerOptions['user'] ?? null,

            // DALL-E 3 specific parameters
            'quality' => $providerOptions['quality'] ?? null,
            'style' => $providerOptions['style'] ?? null,

            // GPT-Image-1 specific parameters
            'background' => $providerOptions['background'] ?? null,
            'moderation' => $providerOptions['moderation'] ?? null,
            'output_compression' => $providerOptions['output_compression'] ?? null,
            'output_format' => $providerOptions['output_format'] ?? null,

            // Image editing parameters (GPT-Image-1 and DALL-E 2)
            'image' => $providerOptions['image'] ?? null,
            'mask' => $providerOptions['mask'] ?? null,
        ];

        // Include any additional options not explicitly handled above
        $additionalOptions = array_diff_key($providerOptions, $supportedOptions);

        return array_merge(
            $baseData,
            Arr::whereNotNull($supportedOptions),
            $additionalOptions
        );
    }
}
