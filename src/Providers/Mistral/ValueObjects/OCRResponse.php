<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\ValueObjects;

readonly class OCRResponse
{
    /**
     * @param  array<OCRPageResponse>  $pages
     * @param  array{pages_processed: int, doc_size_bytes: int }  $usageInfo
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
        $pages = [];
        foreach (data_get($response, 'pages', []) as $page) {
            $pages[] = OCRPageResponse::fromResponse($page);
        }

        return new self(
            model: $model,
            pages: $pages,
            usageInfo: data_get($response, 'usage_info', [
                'pages_processed' => 0,
                'doc_size_bytes' => 0,
            ]),
        );
    }

    public function toText(): string
    {
        return collect($this->pages)->map(
            fn (OCRPageResponse $page): string => $page->markdown)->join("\n\n");
    }
}
