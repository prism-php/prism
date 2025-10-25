<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use HasPrompts;
    use HasProviderOptions;

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
            systemPrompts: $this->systemPrompts,
            prompt: $this->prompt,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            additionalContent: $this->additionalContent,
            providerOptions: $this->providerOptions,
        );
    }
}
