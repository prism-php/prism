<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsFinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsMeta;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsUsage;
use Prism\Prism\Providers\Perplexity\Concerns\HandlesHttpRequests;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;

class Text
{
    use ExtractsAdditionalContent;
    use ExtractsFinishReason;
    use ExtractsMeta;
    use ExtractsUsage;
    use HandlesHttpRequests;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(Request $request): TextResponse
    {
        $response = $this->sendRequest($this->client, $request);
        $data = $response->json();

        return new TextResponse(
            steps: collect(),
            text: data_get($data, 'choices.{last}.message.content'),
            finishReason: $this->extractsFinishReason($data),
            toolCalls: [],
            toolResults: [],
            usage: $this->extractUsage($data),
            meta: $this->extractsMeta($data),
            messages: collect($request->messages()),
            additionalContent: $this->extractsAdditionalContent($data),
        );
    }
}
