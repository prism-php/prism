<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Illuminate\Support\Arr;
use InvalidArgumentException;
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

        $parts = [
            [
                'text' => $request->prompt(),
            ],
        ];

        if (isset($providerOptions['image'])) {
            $resource = $providerOptions['image'];
            $imageContent = is_resource($resource) ? stream_get_contents($resource) : false;
            if (! $imageContent) {
                throw new InvalidArgumentException('Image must be a valid resource.');
            }

            $parts[] = [
                'inline_data' => Arr::whereNotNull([
                    'mime_type' => $providerOptions['image_mime_type'] ?? null,
                    'data' => base64_encode($imageContent),
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
