<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Ollama\Maps\FinishReasonMap;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'done_reason', ''));
    }
}
