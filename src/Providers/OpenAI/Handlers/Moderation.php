<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Moderation\Request;
use Prism\Prism\Moderation\Response as ModerationResponse;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ModerationResult;

class Moderation
{
    use ProcessRateLimits;
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client) {}

    public function handle(Request $request): ModerationResponse
    {
        $response = $this->sendRequest($request);

        $this->validateResponse($response);

        $data = $response->json();

        return new ModerationResponse(
            results: array_map(
                ModerationResult::fromArray(...),
                data_get($data, 'results', [])
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', $request->model()),
                rateLimits: $this->processRateLimits($response),
            ),
            raw: $data,
        );
    }

    protected function sendRequest(Request $request): Response
    {
        $inputs = $request->inputs();
        $hasImages = $this->hasImages($inputs);
        if ($hasImages) {
            $input = $this->formatInputs($inputs);
        } else {
            $input = count($inputs) === 1 ? $inputs[0] : $inputs;
        }

        /** @var Response $response */
        $response = $this->client->post(
            'moderations',
            array_merge([
                'input' => $input,
            ], array_filter([
                'model' => $request->model() ?: null,
                ...($request->providerOptions() ?? []),
            ]))
        );

        return $response;
    }

    /**
     * Check if any inputs are Image objects
     *
     * @param  array<string|Image>  $inputs
     */
    protected function hasImages(array $inputs): bool
    {
        foreach ($inputs as $input) {
            if ($input instanceof Image) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format inputs for OpenAI moderation API
     * Text inputs: { "type": "text", "text": "..." }
     * Image inputs: { "type": "image_url", "image_url": { "url": "..." } }
     *
     * @param  array<string|Image>  $inputs
     * @return array<array<string, mixed>>
     */
    protected function formatInputs(array $inputs): array
    {
        $formatted = [];

        foreach ($inputs as $input) {
            if ($input instanceof Image) {
                $formatted[] = $this->formatImageInput($input);
            } else {
                $formatted[] = [
                    'type' => 'text',
                    'text' => $input,
                ];
            }
        }

        return $formatted;
    }

    /**
     * Format an Image object for OpenAI moderation API
     *
     * @return array{type: string, image_url: array{url: string}}
     */
    protected function formatImageInput(Image $image): array
    {
        $imageUrl = [];

        if ($image->isFileId()) {
            // File IDs are not supported in moderation API, convert to base64
            $imageUrl['url'] = sprintf(
                'data:%s;base64,%s',
                $image->mimeType(),
                $image->base64()
            );
        } elseif ($image->isUrl()) {
            $imageUrl['url'] = (string) $image->url();
        } else {
            $imageUrl['url'] = sprintf(
                'data:%s;base64,%s',
                $image->mimeType(),
                $image->base64()
            );
        }

        return [
            'type' => 'image_url',
            'image_url' => $imageUrl,
        ];
    }
}
