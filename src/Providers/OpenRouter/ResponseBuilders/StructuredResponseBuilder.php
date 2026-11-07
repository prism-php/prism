<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\ResponseBuilders;

use Prism\Prism\Structured\ResponseBuilder;

readonly class StructuredResponseBuilder extends ResponseBuilder
{
    use AggregatesBilling;
}
