<?php

declare(strict_types=1);

namespace Prism\Prism\Models;

use Closure;
use Prism\Prism\Concerns\ConfiguresClient;

class ListModelsRequest
{
    use ConfiguresClient;

    /** @return array<string, mixed> */
    public function clientOptions(): array
    {
        return $this->clientOptions;
    }

    /** @return array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool} */
    public function clientRetry(): array
    {
        return $this->clientRetry;
    }
}
