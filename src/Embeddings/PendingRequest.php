<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;

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

        $request = $this->toRequest();

        if (config('prism.telemetry.enabled', false)) {
            $spanId = Str::uuid()->toString();
            $parentSpanId = Context::get('prism.telemetry.current_span_id');
            $rootSpanId = Context::get('prism.telemetry.root_span_id') ?? $spanId;

            Context::add('prism.telemetry.current_span_id', $spanId);
            Context::add('prism.telemetry.root_span_id', $rootSpanId);

            Event::dispatch(new EmbeddingGenerationStarted(
                spanId: $spanId,
                request: $request,
                context: [
                    'parent_span_id' => $parentSpanId,
                    'root_span_id' => $rootSpanId,
                ]
            ));

            try {
                $response = $this->provider->embeddings($request);

                Event::dispatch(new EmbeddingGenerationCompleted(
                    spanId: $spanId,
                    request: $request,
                    response: $response,
                    context: [
                        'parent_span_id' => $parentSpanId,
                        'root_span_id' => $rootSpanId,
                    ]
                ));

                return $response;
            } finally {
                Context::add('prism.telemetry.current_span_id', $parentSpanId);
            }
        }

        return $this->provider->embeddings($request);
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
