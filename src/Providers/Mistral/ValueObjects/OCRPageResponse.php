<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\ValueObjects;

readonly class OCRPageResponse
{
    /**
     * @param array{
     *     id: string,
     *     top_left_x: int|null,
     *     top_left_y: int|null,
     *     bottom_right_x: int|null,
     *     bottom_right_y: int|null,
     *     image_base64: string|null,
     * } $images
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
     *     top_left_x: int,
     *     top_left_y: int,
     *     bottom_right_x: int,
     *     bottom_right_y: int,
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
                'id' => (string) data_get($image, 'id', ''),
                'top_left_x' => (int) data_get($image, 'top_left_x', null),
                'top_left_y' => (int) data_get($image, 'top_left_y', null),
                'bottom_right_x' => (int) data_get($image, 'bottom_right_x', null),
                'bottom_right_y' => (int) data_get($image, 'bottom_right_y', null),
                'image_base64' => (string) data_get($image, 'image_base64', null),
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
