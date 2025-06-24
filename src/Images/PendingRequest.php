<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;

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

        try {
            return $this->provider->images($this->toRequest());
        } catch (RequestException $e) {
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
