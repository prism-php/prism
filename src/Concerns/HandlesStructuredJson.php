<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HandlesStructuredJson
{
    /**
     * @return array<string, mixed>
     */
    protected function extractStructuredData(string $text): array
    {
        if ($text === '' || $text === '0') {
            return [];
        }

        try {
            return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }
}
