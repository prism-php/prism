<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\ImageRequestMap;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    use ProcessRateLimits;
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
                promptTokens: data_get($data, 'usage.input_tokens', data_get($data, 'usage.prompt_tokens', 0)),
                completionTokens: data_get($data, 'usage.output_tokens', data_get($data, 'usage.completion_tokens', 0)),
            ),
            meta: new Meta(
                id: data_get($data, 'id', 'img_'.bin2hex(random_bytes(8))),
                model: data_get($data, 'model', $request->model()),
                rateLimits: $this->processRateLimits($response),
            ),
            images: $images,
            additionalContent: Arr::whereNotNull([
                'input_tokens_details' => data_get($data, 'usage.input_tokens_details'),
                'quality' => data_get($data, 'quality'),
                'size' => data_get($data, 'size'),
                'output_format' => data_get($data, 'output_format'),
                'background' => data_get($data, 'background'),
            ]),
            raw: $data,
        );

        return $responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        if ($request->additionalContent()) {
            return $this->sendImageEditRequest($request);
        }

        /** @var ClientResponse $response */
        $response = $this->client->post('images/generations', ImageRequestMap::map($request));

        return $response;
    }

    protected function sendImageEditRequest(Request $request): ClientResponse
    {
        /** @var Image $image */
        foreach ($request->additionalContent() as $index => $image) {
            $this
                ->client
                ->attach(
                    'image[]',
                    $image->resource(),
                    $image->filename() ?: "image-{$index}",
                    ['Content-Type' => $image->mimeType()],
                );
        }

        if ($mask = $request->providerOptions('mask')) {
            if (! $mask instanceof Image) {
                throw new InvalidArgumentException('Mask must be an instance of Image value object');
            }

            $this
                ->client
                ->attach(
                    'mask',
                    $mask->resource(),
                );
        }

        /** @var ClientResponse $response */
        $response = $this
            ->client
            ->post('images/edits', ImageRequestMap::map($request));

        return $response;
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
