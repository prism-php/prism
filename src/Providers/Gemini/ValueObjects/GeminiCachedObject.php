<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

use Illuminate\Support\Carbon;

readonly class GeminiCachedObject
{
    public function __construct(
        public string $model,
        public string $name,
        public int $tokens,
        public Carbon $expiresAt
    ) {}

    /**
     * @param  array<string,mixed>  $response
     */
    public static function fromResponse(string $model, array $response): self
    {
        return new self(
            model: $model,
            name: data_get($response, 'name', ''),
            tokens: data_get($response, 'usageMetadata.totalTokenCount', 0),
            expiresAt: Carbon::parse(data_get($response, 'expireTime'))
        );
    }
}
