<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsFinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsMeta;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsStructuredOutput;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsUsage;
use Prism\Prism\Providers\Perplexity\Concerns\HandlesHttpRequests;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;

class Structured
{
    use ExtractsAdditionalContent;
    use ExtractsFinishReason;
    use ExtractsMeta;
    use ExtractsStructuredOutput;
    use ExtractsUsage;
    use HandlesHttpRequests;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(StructuredRequest $request): StructuredResponse
    {
        $response = $this->sendRequest($this->client, $request);

        $data = $response->json();
        $rawContent = data_get($data, 'choices.{last}.message.content');

        return new StructuredResponse(
            steps: collect(),
            text: $rawContent,
            structured: $this->parseStructuredOutput($rawContent),
            finishReason: $this->extractsFinishReason($data),
            usage: $this->extractUsage($data),
            meta: $this->extractsMeta($data),
            additionalContent: $this->extractsAdditionalContent($data),
        );
    }
}
