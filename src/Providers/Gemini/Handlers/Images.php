<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\Gemini\Concerns\ValidatesResponse;
use Prism\Prism\Providers\Gemini\Maps\ImageRequestMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        $images = $this->extractImages($data);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                promptTokens: data_get($data, 'usageMetadata.promptTokenCount', 0),
                completionTokens: data_get($data, 'usageMetadata.candidatesTokenCount', 0),
            ),
            meta: new Meta(
                id: data_get($data, 'responseId', data_get($data, 'id', '')),
                model: data_get($data, 'modelVersion', ''),
            ),
            images: $images,
            raw: $data,
        );

        return $responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $endpoint = $request->model();
        $endpoint .= (str_contains($request->model(), 'gemini') ? ':generateContent' : ':predict');

        /** @var ClientResponse $response */
        $response = $this->client->post($endpoint, ImageRequestMap::map($request));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GeneratedImage[]
     */
    protected function extractImages(array $data): array
    {
        $imageParts = data_get($data, 'predictions', []);
        if (empty($imageParts)) {
            $parts = data_get($data, 'candidates.0.content.parts', []);
            $imageParts = array_column(
                array_filter($parts, fn (array $part) => data_get($part, 'inlineData.data')),
                'inlineData'
            );
        }

        return array_map(fn (array $image): GeneratedImage => new GeneratedImage(
            base64: data_get($image, 'bytesBase64Encoded', data_get($image, 'data')),
            mimeType: data_get($image, 'mimeType')
        ), $imageParts);
    }
}
