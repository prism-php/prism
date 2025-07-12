<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\OpenAI\Concerns\HandleResponseError;
use Prism\Prism\Providers\OpenAI\Maps\ImageRequestMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    use HandleResponseError;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        $this->handleResponseError();

        $data = $response->json();

        $images = $this->extractImages($data);

        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens', data_get($data, 'usage.prompt_tokens', 0)),
                completionTokens: data_get($data, 'usage.output_tokens', data_get($data, 'usage.completion_tokens', 0)),
            ),
            meta: new Meta(
                id: data_get($data, 'id', 'img_'.bin2hex(random_bytes(8))),
                model: data_get($data, 'model', $request->model()),
            ),
            images: $images,
        );

        return $responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        $this->httpResponse = $this->client->post('images/generations', ImageRequestMap::map($request));

        return $this->httpResponse;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GeneratedImage[]
     */
    protected function extractImages(array $data): array
    {
        $images = [];

        foreach (data_get($data, 'data', []) as $imageData) {
            $images[] = new GeneratedImage(
                url: data_get($imageData, 'url'),
                base64: data_get($imageData, 'b64_json'),
                revisedPrompt: data_get($imageData, 'revised_prompt'),
            );
        }

        return $images;
    }
}
