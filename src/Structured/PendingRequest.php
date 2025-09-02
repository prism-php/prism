<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresModels;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresStructuredOutput;
use Prism\Prism\Concerns\HasMessages;
use Prism\Prism\Concerns\HasPrompts;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Concerns\HasSchema;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresModels;
    use ConfiguresProviders;
    use ConfiguresStructuredOutput;
    use HasMessages;
    use HasPrompts;
    use HasProviderOptions;
    use HasSchema;

    /**
     * @deprecated Use `asStructured` instead.
     */
    public function generate(): Response
    {
        return $this->asStructured();
    }

    public function asStructured(): Response
    {
        $request = $this->toRequest();

        try {
            return $this->provider->structured($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function toRequest(): Request
    {
        if ($this->messages && $this->prompt) {
            throw PrismException::promptOrMessages();
        }

        $messages = $this->messages;

        if ($this->prompt) {
            $messages[] = new UserMessage($this->prompt, $this->additionalContent);
        }

        if (! $this->schema instanceof \Prism\Prism\Contracts\Schema) {
            throw new PrismException('A schema is required for structured output');
        }

        return new Request(
            systemPrompts: $this->systemPrompts,
            model: $this->model,
            providerKey: $this->providerKey(),
            prompt: $this->prompt,
            messages: $messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            topP: $this->topP,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            schema: $this->schema,
            mode: $this->structuredMode,
            providerOptions: $this->providerOptions,
        );
    }
}
