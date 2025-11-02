<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Structured\Request;

trait ManagesStructuredSteps
{
    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }
}
