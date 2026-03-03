<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    public function __construct(
        protected PendingRequest $client,
        protected string $apiKey,
    ) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $images = $this->extractImages($data);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(promptTokens: 0, completionTokens: 0),
            meta: new Meta(
                id: (string) data_get($data, 'id', 'ml_'.bin2hex(random_bytes(8))),
                model: $request->model(),
            ),
            images: $images,
            raw: $data,
        );

        return $responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $payload = array_merge(
            [
                'key'                 => $this->apiKey,
                'prompt'              => $request->prompt(),
                'model_id'            => $request->model(),
                'width'               => (string) ($request->providerOptions('width') ?? 1024),
                'height'              => (string) ($request->providerOptions('height') ?? 1024),
                'samples'             => (string) ($request->providerOptions('samples') ?? 1),
                'num_inference_steps' => (string) ($request->providerOptions('num_inference_steps') ?? 30),
                'guidance_scale'      => $request->providerOptions('guidance_scale') ?? 7.5,
                'safety_checker'      => $request->providerOptions('safety_checker') ?? 'no',
            ],
            array_filter([
                'negative_prompt' => $request->providerOptions('negative_prompt'),
                'seed'            => $request->providerOptions('seed'),
            ])
        );

        /** @var ClientResponse $response */
        $response = $this->client->post('images/text2img', $payload);

        return $response;
    }

    protected function validateResponse(ClientResponse $response): void
    {
        if ($response->status() === 429) {
            throw new PrismRateLimitedException([]);
        }

        if (! $response->successful()) {
            throw PrismException::providerResponseError(
                sprintf(
                    'ModelsLab API error %d: %s',
                    $response->status(),
                    $response->body()
                )
            );
        }

        $data = $response->json();

        if (data_get($data, 'status') === 'error') {
            $message = data_get($data, 'message') ?? data_get($data, 'messege') ?? 'Unknown error';
            throw PrismException::providerResponseError("ModelsLab error: {$message}");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GeneratedImage[]
     */
    protected function extractImages(array $data): array
    {
        $urls = data_get($data, 'output', []);

        if (empty($urls)) {
            return [];
        }

        return array_map(
            fn (string $url): GeneratedImage => new GeneratedImage(url: $url),
            $urls
        );
    }
}
