<?php

declare(strict_types=1);

namespace Prism\Prism\Moderation;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Image;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresProviders;
    use HasProviderOptions;

    /** @var array<string|Image> */
    protected array $inputs = [];

    /**
     * Add one or more inputs to moderate.
     * Accepts strings, Image objects, or arrays of either.
     *
     * @param  string|Image|array<string|Image>  ...$inputs
     */
    public function withInput(string|Image|array ...$inputs): self
    {
        foreach ($inputs as $input) {
            if (is_array($input)) {
                foreach ($input as $item) {
                    if (! is_string($item) && ! $item instanceof Image) {
                        throw new PrismException('Array items must be strings or Image instances');
                    }
                    $this->inputs[] = $item;
                }
            } elseif (is_string($input) || $input instanceof Image) {
                $this->inputs[] = $input;
            } else {
                throw new PrismException('Input must be a string, Image instance, or array of strings/Images');
            }
        }

        return $this;
    }

    public function asModeration(): Response
    {

        if ($this->inputs === []) {
            throw new PrismException('Moderation input is required');
        }

        $request = $this->toRequest();

        try {
            return $this->provider->moderation($request);
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
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            providerOptions: $this->providerOptions
        );
    }
}
