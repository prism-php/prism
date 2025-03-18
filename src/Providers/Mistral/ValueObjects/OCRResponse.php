<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\ValueObjects;

readonly class OCRResponse
{
    /**
     * @param array{
     *     index: string,
     *     markdown: string,
     *     images:array{
     *          id: string,
     *          top_left_x: int,
     *          top_left_y: int,
     *          bottom_right_x: int,
     *          bottom_right_y: int,
     *          image_base64: string,
     *     },
     *     dimensions: array{
     *          dpi: int,
     *          height: int,
     *          width: int,
     *     }
     *     } $pages
     * @param  array{pages_processed: int, }  $usageInfo
     */
    public function __construct(
        public string $model,
        public array $pages,
        public array $usageInfo,
    ) {}

    /**
     * @param  array<string,mixed>  $response
     */
    public static function fromResponse(string $model, array $response): self
    {
        return new self(
            model: $model,
            pages: data_get($response, 'pages', [
                [
                    'index' => 0,
                    'markdown' => '',
                    'images' => [
                        [
                            'id' => '',
                            'top_left_x' => 0,
                            'top_left_y' => 0,
                            'bottom_right_x' => 0,
                            'bottom_right_y' => 0,
                            'image_base64' => '',
                        ],
                    ],
                    'dimensions' => [
                        'dpi' => 0,
                        'height' => 0,
                        'width' => 0,
                    ],
                ],
            ]),
            usageInfo: data_get($response, 'usage_info', [
                'pages_processed' => 0,
                'doc_size_bytes' => 0,
            ]),
        );
    }
}
