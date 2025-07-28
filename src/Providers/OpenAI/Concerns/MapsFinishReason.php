<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Illuminate\Support\Arr;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(
            Arr::last(data_get($data, 'output.*.status', [])),
            Arr::last(data_get($data, 'output.*.type', [])),
        );
    }
}
