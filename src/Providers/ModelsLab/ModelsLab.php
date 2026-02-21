<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Images\Request as ImagesRequest;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Providers\ModelsLab\Handlers\Images;
use Prism\Prism\Providers\Provider;

class ModelsLab extends Provider
{
    use InitializesClient;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
    ) {}

    #[\Override]
    public function images(ImagesRequest $request): ImagesResponse
    {
        return (new Images(
            client: $this->client($request->clientOptions(), $request->clientRetry()),
            apiKey: $this->apiKey,
        ))->handle($request);
    }

    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        return $this->baseClient()
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($this->url);
    }
}
