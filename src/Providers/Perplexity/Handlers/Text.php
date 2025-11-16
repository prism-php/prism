<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;

class Text extends BaseHandler
{
    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(Request $request): TextResponse
    {
        $response = $this->sendRequest($this->client, $request);

        return new TextResponse(
            steps: collect(),
            text: $response->json('choices.{last}.message.content'),
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: $this->getUsageFromClientResponse($response),
            meta: $this->getMetaFromClientResponse($response),
            messages: collect($request->messages()),
            additionalContent: $this->getAdditionalContentFromClientResponse($response),
        );
    }
}
