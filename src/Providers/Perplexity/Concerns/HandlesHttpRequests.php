<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Providers\Perplexity\Maps\MessagesMapper;

trait HandlesHttpRequests
{
    protected function sendRequest(PendingRequest $client, PrismRequest $request): Response
    {
        return $client->post(
            '/chat/completions',
            $this->buildHttpRequestPayload($request)
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    protected function buildHttpRequestPayload(PrismRequest $request): array
    {
        return array_merge([
            'model' => $request->model(),
            'messages' => (new MessagesMapper($request->messages()))->toPayload(),
            'max_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'top_k' => $request->providerOptions('top_k'),
            'reasoning_effort' => $request->providerOptions('reasoning_effort'),
            'web_search_options' => $request->providerOptions('web_search_options'),
            'search_mode' => $request->providerOptions('search_mode'),
            'language_preference' => $request->providerOptions('language_preference'),
            'search_domain_filter' => $request->providerOptions('search_domain_filter'),
            'return_images' => $request->providerOptions('return_images'),
            'return_related_questions' => $request->providerOptions('return_related_questions'),
            'search_recency_filter' => $request->providerOptions('search_recency_filter'),
            'search_after_date_filter' => $request->providerOptions('search_after_date_filter'),
            'search_before_date_filter' => $request->providerOptions('search_before_date_filter'),
            'last_updated_after_filter' => $request->providerOptions('last_updated_after_filter'),
            'last_updated_before_filter' => $request->providerOptions('last_updated_before_filter'),
            'presence_penalty' => $request->providerOptions('presence_penalty'),
            'frequency_penalty' => $request->providerOptions('frequency_penalty'),
            'disable_search' => $request->providerOptions('disable_search'),
            'enable_search_classifier' => $request->providerOptions('enable_search_classifier'),
            'media_response' => $request->providerOptions('media_response'),
        ]));
    }
}
