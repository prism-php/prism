<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\OpenCodeZen\Maps\FinishReasonMap;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = Arr::get($data, 'choices.0.finish_reason', '');

        return FinishReasonMap::map($finishReason ?? '');
    }
}
