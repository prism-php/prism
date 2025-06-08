<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Testing;

use Prism\Prism\Http\Factory;
use Prism\Prism\Http\ResponseSequence;

class HttpFake extends Factory
{
    /**
     * @param  callable|array<string, mixed>|null  $callback
     */
    public function fake(callable|array|null $callback = null): static
    {
        return parent::fake($callback);
    }

    /**
     * @param  array<mixed>  $responses
     */
    public function sequence(array $responses = []): ResponseSequence
    {
        return parent::sequence($responses);
    }

    public function assertSent(callable $callback): void
    {
        parent::assertSent($callback);
    }

    public function assertNotSent(callable $callback): void
    {
        parent::assertNotSent($callback);
    }

    public function assertSentCount(int $count): void
    {
        parent::assertSentCount($count);
    }

    public function assertNothingSent(): void
    {
        parent::assertNothingSent();
    }
}
