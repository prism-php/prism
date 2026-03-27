<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $topLevelStatus = data_get($data, 'status', '');

        if ($topLevelStatus === 'incomplete') {
            return match (data_get($data, 'incomplete_details.reason')) {
                'content_filter' => FinishReason::ContentFilter,
                default => FinishReason::Length,
            };
        }

        return FinishReasonMap::map(
            data_get($data, 'output.{last}.status', ''),
            data_get($data, 'output.{last}.type', ''),
        );
    }
}
