<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;

class ImageRequestMap
{
    public static function map(Request $request): array
    {
        return match (str_contains($request->model(), 'gemini')) {
            true => self::geminiOptions($request),
            false => self::imagenOptions($request),
        };
    }

    private static function geminiOptions(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $parts = [
            [
                'text' => $request->prompt() ?? null,
            ],
        ];

        if (isset($providerOptions['image'])) {
            $parts[] = [
                'inline_data' => Arr::whereNotNull([
                    'mime_type' => $providerOptions['image_mime_type'] ?? null,
                    'data' => $providerOptions['image'] ?? null,
                ]),
            ];
        }

        return [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ];
    }

    private static function imagenOptions(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $options = [
            'instances' => [
                [
                    'prompt' => $request->prompt() ?? null,
                ],
            ],
        ];

        $parameters = Arr::whereNotNull([
            'numberOfImages' => $providerOptions['n'] ?? null,
            'sampleImageSize' => $providerOptions['size'] ?? null,
            'aspectRatio' => $providerOptions['aspect_ratio'] ?? null,
            'personGeneration' => $providerOptions['person_generation'] ?? null,
        ]);

        if (! empty($parameters)) {
            $options['parameters'] = $parameters;
        }

        return $options;
    }
}
