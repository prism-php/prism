<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Closure;

trait ConfiguresClient
{
    /** @var array<string, mixed> */
    protected array $clientOptions = [];

    /** @var array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool} */
    protected array $clientRetry = [0];

    /**
     * @param  array<string, mixed>  $options
     */
    public function withClientOptions(array $options): self
    {
        $this->clientOptions = $options;

        return $this;
    }

    /**
     * @param  array<int>|int  $times
     */
    public function withClientRetry(
        array|int $times,
        Closure|int $sleepMilliseconds = 0,
        ?callable $when = null,
        bool $throw = true
    ): self {
        $this->clientRetry = [$times, $sleepMilliseconds, $when, $throw];

        return $this;
    }
}
