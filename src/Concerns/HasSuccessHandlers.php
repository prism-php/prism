<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response as TextResponse;

trait HasSuccessHandlers
{
    /** @var array<int, callable> */
    protected array $handlers = [];

    public function onSuccess(callable $callable)
    {
        $this->handlers[] = $callable;

        return $this;
    }

    public function processSuccessHandlers(PrismRequest $request, TextResponse|StructuredResponse $response): void
    {
        foreach ($this->handlers as $handler) {
            $handler($request, $response);
        }
    }
}
