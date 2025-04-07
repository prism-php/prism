<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

trait HasCitations
{
    /**
     * @param  array<string, mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractCitations(array $data, string $provider): ?array
    {
        return match ($provider) {
            'anthropic' => $this->extractAnthropicCitations($data),
            'gemini' => $this->extractGeminiCitations($data),
            'perplexity' => $this->extractPerplexityCitations($data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractAnthropicCitations(array $data): ?array
    {
        if (array_filter(data_get($data, 'content.*.citations')) === []) {
            return null;
        }

        return Arr::map(
            data_get($data, 'content', []),
            fn ($contentBlock): MessagePartWithCitations => $this->mapFromAnthropicContentBlock($contentBlock)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractGeminiCitations(array $data): ?array
    {
        if (data_get($data, 'candidates.0.groundingMetadata.groundingSupports') === null) {
            return null;
        }

        return $this->mapFromGeminiGroundings(
            data_get($data, 'candidates.0.groundingMetadata.groundingSupports', []),
            data_get($data, 'candidates.0.groundingMetadata.groundingChunks', [])
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractPerplexityCitations(array $data): ?array
    {
        // To be implemented when Perplexity provider is added
        return null;
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    abstract protected function mapFromAnthropicContentBlock(array $contentBlock): MessagePartWithCitations;

    /**
     * @param  array<string, mixed>  $groundingSupports
     * @param  array<array<string, array<string, string>>>  $groundingChunks
     * @return MessagePartWithCitations[]
     */
    abstract protected function mapFromGeminiGroundings(array $groundingSupports, array $groundingChunks): array;
}
