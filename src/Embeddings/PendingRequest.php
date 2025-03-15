<?php

declare(strict_types=1);

namespace Prism\Prism\Embeddings;

use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderMeta;
use Prism\Prism\Exceptions\PrismException;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderMeta;

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

        return $this->provider->embeddings($this->toRequest());
    }

    protected function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            inputs: $this->inputs,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerMeta: $this->providerMeta
        );
    }
}
