<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Generator;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Throwable;

abstract class Provider
{
    public function text(TextRequest $request): TextResponse
    {
        throw PrismException::unsupportedProviderAction('text', class_basename($this));
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        throw PrismException::unsupportedProviderAction('structured', class_basename($this));
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        throw PrismException::unsupportedProviderAction('embeddings', class_basename($this));
    }

    public function images(ImagesRequest $request): ImagesResponse
    {
        throw PrismException::unsupportedProviderAction('images', class_basename($this));
    }

    /**
     * @return Generator<Chunk>
     */
    public function stream(TextRequest $request): Generator
    {
        throw PrismException::unsupportedProviderAction(__METHOD__, class_basename($this));
    }

    public function handleRequestExceptions(string $model, Throwable $e): never
    {
        if ($e instanceof PrismException) {
            throw $e;
        }

        if (! $e instanceof RequestException) {
            throw PrismException::providerRequestError($model, $e);
        }

        throw PrismException::providerRequestError($model, $e);
    }
}
