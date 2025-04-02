<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\ValueObjects;

readonly class OCRPageResponse
{
    /**
     * @param array<int, array{
     *     id: string,
     *     top_left_x: int|null,
     *     top_left_y: int|null,
     *     bottom_right_x: int|null,
     *     bottom_right_y: int|null,
     *     image_base64: string|null,
     * }> $images
     * @param array{
     *      dpi: int,
     *      height: int,
     *      width: int,
     * } $dimensions
     */
    public function __construct(
        public int $index,
        public string $markdown,
        public array $images,
        public array $dimensions,
    ) {}

    /**
     * @param array{
     *     index: int,
     *     markdown: string,
     *     images: array{
     *     id: string,
     *     top_left_x: mixed,
     *     top_left_y: mixed,
     *     bottom_right_x: mixed,
     *     bottom_right_y: mixed,
     *     image_base64: string,
     *     }[],
     *     dimensions: array{
     *     dpi: int,
     *     height: int,
     *     width: int,
     *     }
     * } $page
     */
    public static function fromResponse(array $page): self
    {
        $images = [];

        foreach (data_get($page, 'images', []) as $image) {
            $images[] = [
                'id' => data_get($image, 'id', ''),
                'top_left_x' => data_get($image, 'top_left_x', null),
                'top_left_y' => data_get($image, 'top_left_y', null),
                'bottom_right_x' => data_get($image, 'bottom_right_x', null),
                'bottom_right_y' => data_get($image, 'bottom_right_y', null),
                'image_base64' => data_get($image, 'image_base64', null),
            ];
        }

        return new self(
            index: data_get($page, 'index', 0),
            markdown: data_get($page, 'markdown', ''),
            images: $images,
            dimensions: data_get($page, 'dimensions', [
                'dpi' => 0,
                'height' => 0,
                'width' => 0,
            ]),
        );
    }
}
