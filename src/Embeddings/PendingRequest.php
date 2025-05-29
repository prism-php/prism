<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Exceptions\PrismException;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;

    /** @var array<string> */
    protected array $inputs = [];

    public function fromInput(string $input): self
    {
        $this->inputs[] = $input;

        return $this;
    }

    /**
     * @param  array<string>  $inputs
     */
    public function fromArray(array $inputs): self
    {
        $this->inputs = array_merge($this->inputs, $inputs);

        return $this;
    }

    public function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new PrismException(sprintf('%s is not a valid file', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new PrismException(sprintf('%s contents could not be read', $path));
        }

        $this->inputs[] = $contents;

        return $this;
    }

    /**
     * @deprecated Use `asEmbeddings` instead.
     */
    public function generate(): Response
    {
        return $this->asEmbeddings();
    }

    public function asEmbeddings(): \Prism\Prism\Embeddings\Response
    {
        if ($this->inputs === []) {
            throw new PrismException('Embeddings input is required');
        }

        $contextId = Str::uuid()->toString();

        Event::dispatch(new PrismRequestStarted(
            contextId: $contextId,
            operationName: 'embeddings',
            attributes: [
                'provider' => $this->provider::class,
                'model' => $this->model,
                'input_count' => count($this->inputs),
                'total_input_length' => array_sum(array_map('strlen', $this->inputs)),
            ]
        ));

        try {
            $request = $this->toRequest();
            $request->setTelemetryContextId($contextId);
            $response = $this->provider->embeddings($request);

            Event::dispatch(new PrismRequestCompleted(
                contextId: $contextId,
                attributes: [
                    'embedding_count' => count($response->embeddings),
                    'usage_tokens' => $response->usage->tokens,
                ]
            ));

            return $response;
        } catch (\Throwable $e) {
            Event::dispatch(new PrismRequestCompleted(
                contextId: $contextId,
                exception: $e
            ));

            throw $e;
        }
    }

    protected function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            inputs: $this->inputs,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions
        );
    }
}
