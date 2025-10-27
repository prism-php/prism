<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Images\Request;

class ImageRequestMap
{
    /** @return array<string, mixed> */
    public static function map(Request $request): array
    {
        return match (str_contains($request->model(), 'gemini')) {
            true => self::geminiOptions($request),
            false => self::imagenOptions($request),
        };
    }

    /** @return array<string, mixed> */
    protected static function geminiOptions(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $parts = [];

        // Add images first (Gemini best practice for multimodal prompts)
        foreach ($request->additionalContent() as $image) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image->mimeType(),
                    'data' => $image->base64(),
                ],
            ];
        }

        // Add text prompt after images
        $parts[] = [
            'text' => $request->prompt(),
        ];

        return [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => $providerOptions['aspect_ratio'] ?? null,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected static function imagenOptions(Request $request): array
    {
        $providerOptions = $request->providerOptions();

        $options = [
            'instances' => [
                [
                    'prompt' => $request->prompt(),
                ],
            ],
        ];

        $parameters = Arr::whereNotNull([
            'sampleCount' => $providerOptions['n'] ?? null,
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
