<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies;

use Illuminate\Http\Client\Response as HttpResponse;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as PrismResponse;

abstract class AnthropicStructuredStrategy
{
    public function __construct(
        protected Request $request
    ) {
        $this->checkStrategySupport();
    }

    abstract public function appendMessages(): void;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract public function mutatePayload(array $payload): array;

    abstract public function mutateResponse(HttpResponse $httpResponse, PrismResponse $prismResponse): PrismResponse;

    abstract protected function checkStrategySupport(): void;
}
