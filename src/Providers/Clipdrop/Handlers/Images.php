<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Clipdrop\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    public function __construct(protected PendingRequest $client) {}

    public function images(Request $request): Response
    {
        if (! $request->prompt()) {
            throw new PrismException('No prompt provided');
        }

        $response = $this->client->asMultipart()->post('https://clipdrop-api.co/text-to-image/v1', [[
            'contents' => $request->prompt(),
            'name' => 'prompt',
        ]]);

        return $this->buildResponse($response);
    }

    public function imageBackground(Request $request): Response
    {
        $image = array_shift($request->additionalContent());

        if (! $image) {
            throw new PrismException('No image provided to remove/replace background');
        }

        $url = 'https://clipdrop-api.co/remove-background/v1';
        $data = [
            'contents' => $image->rawContent(),
            'name' => 'image_file',
        ];

        if ($prompt = $request->prompt()) {
            $url = 'https://clipdrop-api.co/replace-background/v1';
            $data[] = [
                'contents' => $request->prompt(),
                'name' => 'prompt',
            ];
        }

        $response = $this->client->asMultipart()->post($url, [$data]);

        return $this->buildResponse($response);
    }

    public function imageUncrop(Request $request): Response
    {
        $opts = $request->providerOptions();

        $response = $this->client->asMultipart()->post('https://clipdrop-api.co/uncrop/v1', [[
            'contents' => $image->rawContent(),
            'name' => 'image_file',
        ], [
            'contents' => $opts['left'] ?? 0,
            'name' => 'extend_left',
        ], [
            'contents' => $opts['right'] ?? 0,
            'name' => 'extend_right',
        ], [
            'contents' => $opts['top'] ?? 0,
            'name' => 'extend_up',
        ], [
            'contents' => $opts['bottom'] ?? 0,
            'name' => 'extend_down',
        ]]);

        return $this->buildResponse($response);
    }

    public function imageUpscale(Request $request): Response
    {
        if (! $request->prompt()) {
            throw new PrismException('No prompt provided');
        }

        $response = $this->client->asMultipart()->post('https://clipdrop-api.co/image-upscaling/v1/upscale', [[
            'contents' => $image->rawContent(),
            'name' => 'image_file',
        ], [
            'contents' => $request->prompt() ?: 4096,
            'name' => 'target_width',
        ], [
            'contents' => $request->prompt() ?: 4096,
            'name' => 'target_height',
        ]]);

        return $this->buildResponse($response);
    }

    protected function buildResponse(ClientResponse $response): Response
    {
        $image = $this->validate($response);
        $responseBuilder = new ResponseBuilder(
            usage: new Usage(
                completionTokens: $response->header('x-credits-consumed') ?? 0,
            ),
            images: [$image]
        );

        return $responseBuilder->toResponse();
    }

    protected function validate(ClientResponse $response): string
    {
        if ($reponse->status() === 200) {
            return $response->body();
        }

        throw PrismException::providerResponseError(vsprintf(
            'ClipDrop Error: [%s] %s',
            [
                $reponse->status(),
                data_get($response->json(), 'error', 'unknown'),
            ]
        ));
    }
}
