<?php

namespace Prism\Prism\Providers\VoyageAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Meta;

class Embeddings
{
    protected EmbeddingsRequest $request;

    protected Response $httpResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $this->request = $request;

        $this->sendRequest();

        $this->validateResponse();

        $data = $this->httpResponse->json();

        return new EmbeddingsResponse(
            embeddings: array_map(fn (array $item): Embedding => Embedding::fromArray($item['embedding']), data_get($data, 'data', [])),
            usage: new EmbeddingsUsage(
                tokens: data_get($data, 'usage.total_tokens'),
            ),
            meta: new Meta(
                id: '',
                model: data_get($data, 'model', ''),
            ),
        );
    }

    protected function sendRequest(): void
    {
        if ($this->request->hasImages()) {
            $this->sendMultimodalRequest();
        } else {
            $this->sendTextRequest();
        }
    }

    /**
     * Send a text-only embedding request to the /embeddings endpoint.
     */
    protected function sendTextRequest(): void
    {
        $providerOptions = $this->request->providerOptions();

        /** @var Response $response */
        $response = $this->client->post('embeddings', Arr::whereNotNull([
            'model' => $this->request->model(),
            'input' => $this->request->inputs(),
            'input_type' => $providerOptions['inputType'] ?? null,
            'truncation' => $providerOptions['truncation'] ?? null,
        ]));

        $this->httpResponse = $response;
    }

    /**
     * Send a multimodal embedding request to the /multimodalembeddings endpoint.
     *
     * Each input is an object with a "content" array containing text and/or image parts.
     * Images and text inputs are combined into separate multimodal input objects.
     *
     * @see https://docs.voyageai.com/reference/multimodal-embeddings-api
     */
    protected function sendMultimodalRequest(): void
    {
        $providerOptions = $this->request->providerOptions();
        $inputs = [];

        // Each text input becomes a separate multimodal input
        foreach ($this->request->inputs() as $text) {
            $inputs[] = [
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
            ];
        }

        // Each image becomes a separate multimodal input
        foreach ($this->request->images() as $image) {
            $inputs[] = [
                'content' => [
                    $this->mapImage($image),
                ],
            ];
        }

        /** @var Response $response */
        $response = $this->client->post('multimodalembeddings', Arr::whereNotNull([
            'model' => $this->request->model(),
            'inputs' => $inputs,
            'input_type' => $providerOptions['inputType'] ?? null,
            'truncation' => $providerOptions['truncation'] ?? null,
        ]));

        $this->httpResponse = $response;
    }

    /**
     * Map a Prism Image to a Voyage multimodal content part.
     *
     * @return array{type: string, image_base64?: string, image_url?: string}
     */
    protected function mapImage(Image $image): array
    {
        if ($image->isUrl()) {
            return [
                'type' => 'image_url',
                'image_url' => (string) $image->url(),
            ];
        }

        $mimeType = $image->mimeType() ?? 'image/jpeg';

        return [
            'type' => 'image_base64',
            'image_base64' => "data:{$mimeType};base64,{$image->base64()}",
        ];
    }

    protected function validateResponse(): void
    {
        $data = $this->httpResponse->json();

        if (! $data || data_get($data, 'detail')) {
            throw PrismException::providerResponseError('Voyage AI error: '.data_get($data, 'detail'));
        }
    }
}
