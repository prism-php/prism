<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Support\Trace;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use HasProviderOptions;

    protected string $prompt = '';

    public function withPrompt(string|View $prompt): self
    {
        $this->prompt = is_string($prompt) ? $prompt : $prompt->render();

        return $this;
    }

    public function generate(): Response
    {
        $request = $this->toRequest();

        Trace::begin('images', fn () => event(new PrismRequestStarted($this->providerKey(), ['request' => $request])));

        try {
            $response = $this->provider->images($request);

            Trace::end(fn () => event(new PrismRequestCompleted($this->providerKey(), ['response' => $response])));

            return $response;
        } catch (RequestException $e) {
            Trace::end(fn () => event(new PrismRequestCompleted(exception: $e)));

            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            prompt: $this->prompt,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions,
        );
    }
}
