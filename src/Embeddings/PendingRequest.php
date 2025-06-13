<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\ValueObjects\Media\Image;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;

    /** @var array<string> */
    protected array $inputs = [];

    /** @var array<Image> */
    protected array $images = [];

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
     * Add an image for embedding generation.
     *
     * Note: Not all providers support image embeddings. Check the provider's
     * documentation to ensure the model you're using supports image input.
     * Common providers that support image embeddings include CLIP-based models
     * and multimodal embedding models like BGE-VL.
     */
    public function fromImage(Image $image): self
    {
        $this->images[] = $image;

        return $this;
    }

    /**
     * Add multiple images for embedding generation.
     *
     * @param  array<Image>  $images
     */
    public function fromImages(array $images): self
    {
        $this->images = array_merge($this->images, $images);

        return $this;
    }

    /**
     * @deprecated Use `asEmbeddings` instead.
     */
    public function generate(): Response
    {
        return $this->asEmbeddings();
    }

    public function asEmbeddings(): Response
    {
        if ($this->inputs === [] && $this->images === []) {
            throw new PrismException('Embeddings input is required (text or images)');
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
            } catch (RequestException $e) {
                $this->provider->handleRequestException($request->model(), $e);
            } finally {
                Context::add('prism.telemetry.current_span_id', $parentSpanId);
            }
        }

        try {
            return $this->provider->embeddings($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    protected function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            providerKey: $this->providerKey(),
            inputs: $this->inputs,
            images: $this->images,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions
        );
    }
}
