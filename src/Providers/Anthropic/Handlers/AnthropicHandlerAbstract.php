<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesResponse;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Throwable;

abstract class AnthropicHandlerAbstract
{
    use HandlesResponse;

    protected Response $httpResponse;

    public function __construct(protected PendingRequest $client, protected PrismRequest $request) {}

    /**
     * @return array<string, mixed>
     */
    abstract public static function buildHttpRequestPayload(PrismRequest $request): array;

    protected function sendRequest(): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'messages',
                static::buildHttpRequestPayload($this->request)
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($this->request->model(), $e);
        }

        $this->handleResponseErrors();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractText(array $data): string
    {
        return array_reduce(data_get($data, 'content', []), function (string $text, array $content): string {
            if (data_get($content, 'type') === 'text') {
                $text .= data_get($content, 'text');
            }

            return $text;
        }, '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return null|MessagePartWithCitations[]
     */
    protected function extractCitations(array $data): ?array
    {
        if (array_filter(data_get($data, 'content.*.citations')) === []) {
            return null;
        }

        return Arr::map(data_get($data, 'content', []), fn ($contentBlock): \Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations => MessagePartWithCitations::fromContentBlock($contentBlock));
    }

    protected function handleResponseErrors(): void
    {
        $this->handleResponseExceptions($this->httpResponse);

        $data = $this->httpResponse->json();

        if (data_get($data, 'type') === 'error') {
            throw PrismException::providerResponseError(vsprintf(
                'Anthropic Error: [%s] %s',
                [
                    data_get($data, 'error.type', 'unknown'),
                    data_get($data, 'error.message'),
                ]
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractThinking(array $data): array
    {
        if ($this->request->providerMeta(Provider::Anthropic, 'thinking.enabled') !== true) {
            return [];
        }

        $thinking = Arr::first(
            data_get($data, 'content', []),
            fn ($content): bool => data_get($content, 'type') === 'thinking'
        );

        return [
            'thinking' => data_get($thinking, 'thinking'),
            'thinking_signature' => data_get($thinking, 'signature'),
        ];
    }
}
