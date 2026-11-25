<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;

class ImageRequestMap
{
    /**
     * @return array<string, mixed>
     */
    public static function map(Request $request, string $apiKey): array
    {
        $providerOptions = $request->providerOptions();

        $baseData = [
            'key' => $apiKey,
            'prompt' => $request->prompt(),
            'model_id' => $request->model(),
        ];

        $supportedOptions = [
            'negative_prompt' => $providerOptions['negative_prompt'] ?? null,
            'width' => $providerOptions['width'] ?? null,
            'height' => $providerOptions['height'] ?? null,
            'samples' => $providerOptions['samples'] ?? null,
            'seed' => $providerOptions['seed'] ?? null,
            'safety_checker' => $providerOptions['safety_checker'] ?? null,
            'base64' => $providerOptions['base64'] ?? null,
            'webhook' => $providerOptions['webhook'] ?? null,
            'track_id' => $providerOptions['track_id'] ?? null,
            'guidance_scale' => $providerOptions['guidance_scale'] ?? null,
            'num_inference_steps' => $providerOptions['num_inference_steps'] ?? null,
            'scheduler' => $providerOptions['scheduler'] ?? null,
            'enhance_prompt' => $providerOptions['enhance_prompt'] ?? null,
            'model_id' => $providerOptions['model_id'] ?? null,
        ];

        $additionalOptions = array_diff_key($providerOptions, $supportedOptions);

        return array_merge(
            $baseData,
            Arr::whereNotNull($supportedOptions),
            $additionalOptions
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapImg2Img(Request $request, string $apiKey, string $initImage): array
    {
        $baseMap = self::map($request, $apiKey);

        $baseMap['init_image'] = $initImage;

        if ($strength = $request->providerOptions('strength')) {
            $baseMap['strength'] = $strength;
        }

        return $baseMap;
    }
}
