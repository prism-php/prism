<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\ModelsLab\Concerns\HandlesAsyncRequests;
use Prism\Prism\Providers\ModelsLab\Maps\ImageRequestMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    use HandlesAsyncRequests;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey
    ) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $data = $response->json();

        $this->validateResponse($data);

        if (($data['status'] ?? '') === 'processing') {
            $data = $this->pollForResult(
                $this->client,
                $data['fetch_result'] ?? '',
                $this->apiKey
            );
        }

        return $this->buildResponse($request, $data);
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        if ($request->additionalContent()) {
            return $this->sendImg2ImgRequest($request);
        }

        /** @var ClientResponse $response */
        $response = $this->client->post(
            'images/text2img',
            ImageRequestMap::map($request, $this->apiKey)
        );

        return $response;
    }

    protected function sendImg2ImgRequest(Request $request): ClientResponse
    {
        $images = $request->additionalContent();
        $firstImage = $images[0] ?? null;

        if (! $firstImage) {
            throw PrismException::providerResponseError('No image provided for img2img request');
        }

        $initImage = $firstImage->hasUrl()
            ? ($firstImage->url() ?? '')
            : 'data:'.$firstImage->mimeType().';base64,'.$firstImage->base64();

        /** @var ClientResponse $response */
        $response = $this->client->post(
            'images/img2img',
            ImageRequestMap::mapImg2Img($request, $this->apiKey, $initImage)
        );

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if (($data['status'] ?? '') === 'error') {
            $message = $data['message'] ?? $data['messege'] ?? 'Unknown error from ModelsLab API';

            throw PrismException::providerResponseError(
                $this->formatErrorMessage($message)
            );
        }
    }

    /**
     * Format error message from various response formats.
     */
    protected function formatErrorMessage(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_array($message)) {
            $errors = [];
            foreach ($message as $fieldErrors) {
                $errors[] = is_array($fieldErrors) ? implode(' ', $fieldErrors) : (string) $fieldErrors;
            }

            return implode(' ', $errors);
        }

        return 'Unknown error from ModelsLab API';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildResponse(Request $request, array $data): Response
    {
        $images = $this->extractImages($data);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
            ),
            meta: new Meta(
                id: (string) ($data['id'] ?? ''),
                model: $request->model(),
            ),
            images: $images,
            additionalContent: [
                'generation_time' => $data['generationTime'] ?? null,
                'seed' => $data['meta']['seed'] ?? null,
            ],
            raw: $data,
        );

        return $responseBuilder->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GeneratedImage[]
     */
    protected function extractImages(array $data): array
    {
        $images = [];
        $outputs = $data['output'] ?? [];

        foreach ($outputs as $output) {
            $images[] = new GeneratedImage(
                url: $output,
            );
        }

        return $images;
    }
}
