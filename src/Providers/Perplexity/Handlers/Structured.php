<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsMeta;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsUsage;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class Structured extends BaseHandler
{
    use ExtractsAdditionalContent;
    use ExtractsMeta;
    use ExtractsUsage;

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

    protected function parseStructuredOutput(string $content): array
    {
        $stringable = Str::of($content);

        if ($stringable->contains('</think>')) {
            $stringable = $stringable->after('</think>')->trim();
        }

        if ($stringable->startsWith('```json')) {
            $stringable = $stringable->after('```json')->trim();
        }

        if ($stringable->startsWith('```')) {
            $stringable = $stringable->substr(3)->trim();
        }

        if ($stringable->endsWith('```')) {
            $stringable = $stringable->substr(0, $stringable->length() - 3)->trim();
        }

        return json_decode($stringable->trim(), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
