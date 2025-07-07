<?php

namespace Prism\Prism\Providers\Anthropic\Handlers\StructuredStrategies;

use Illuminate\Http\Client\Response as HttpResponse;
use Prism\Prism\Structured\Response as PrismResponse;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class JsonModeStructuredStrategy extends AnthropicStructuredStrategy
{
    public function appendMessages(): void
    {
        $this->request->addMessage(new UserMessage(sprintf(
            "Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema: \n %s %s",
            json_encode($this->request->schema()->toArray(), JSON_PRETTY_PRINT),
            ($this->request->providerOptions()['citations'] ?? false)
                ? "\n\n Return the JSON as a single text block with a single set of citations."
                : ''
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mutatePayload(array $payload): array
    {
        return $payload;
    }

    public function mutateResponse(HttpResponse $httpResponse, PrismResponse $prismResponse): PrismResponse
    {
        return $prismResponse;
    }

    protected function checkStrategySupport(): void {}
}
