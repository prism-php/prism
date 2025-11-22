<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsMeta;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsStructuredOutput;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsUsage;
use Prism\Prism\Providers\Perplexity\Concerns\HandlesHttpRequests;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class Structured
{
    use ExtractsAdditionalContent;
    use ExtractsMeta;
    use ExtractsStructuredOutput;
    use ExtractsUsage;
    use HandlesHttpRequests;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(StructuredRequest $request): StructuredResponse
    {
        $request->addMessage(new SystemMessage(sprintf(
            "Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:\n %s",
            json_encode($request->schema()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        )));

        $response = $this->sendRequest($this->client, $request);

        $data = $response->json();
        $rawContent = data_get($data, 'choices.{last}.message.content');

        return new StructuredResponse(
            steps: collect(),
            text: $rawContent,
            structured: $this->parseStructuredOutput($rawContent),
            finishReason: FinishReason::Stop,
            usage: $this->extractUsage($data),
            meta: $this->extractsMeta($data),
            additionalContent: $this->extractsAdditionalContent($data),
        );
    }
}
