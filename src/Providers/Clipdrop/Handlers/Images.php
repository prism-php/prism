<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Clipdrop\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class Images
{
    public function __construct(protected PendingRequest $client) {}

    public function images(Request $request): Response
    {
        if (! $request->prompt()) {
            throw new PrismException('No prompt provided');
        }

        $response = $this->client->post('https://clipdrop-api.co/text-to-image/v1', [
            'contents' => $request->prompt(),
            'name' => 'prompt',
        ]);

        return $this->buildResponse($response);
    }

    public function imageBackground(Request $request): Response
    {
        $images = $request->additionalContent();
        $image = array_shift($images);

        if (! $image) {
            throw new PrismException('No image provided for removing/replacing background');
        }

        $data = [];
        $url = 'https://clipdrop-api.co/remove-background/v1';

        if ($prompt = $request->prompt()) {
            $data = ['prompt' => $request->prompt()];
            $url = 'https://clipdrop-api.co/replace-background/v1';
        }

        $response = $this->client->attach(
            'image_file', $image->rawContent(), $image->filename() ?: 'image', ['Content-Type' => $image->mimeType()]
        )->post($url, $data);

        return $this->buildResponse($response);
    }

    public function imageUncrop(Request $request): Response
    {
        $opts = $request->providerOptions();
        $images = $request->additionalContent();
        $image = array_shift($images);

        if (! $image) {
            throw new PrismException('No image provided for uncropping/extending dimensions');
        }

        $response = $this->client->attach(
            'image_file', $image->rawContent(), $image->filename() ?: 'image', ['Content-Type' => $image->mimeType()]
        )->post('https://clipdrop-api.co/uncrop/v1', [
            'extend_left' => $opts['left'] ?? 0,
            'extend_right' => $opts['right'] ?? 0,
            'extend_up' => $opts['top'] ?? 0,
            'extend_down' => $opts['bottom'] ?? 0,
        ]);

        return $this->buildResponse($response);
    }

    public function imageUpscale(Request $request): Response
    {
        $images = $request->additionalContent();
        $image = array_shift($images);

        if (! $image) {
            throw new PrismException('No image provided for upscaling');
        }

        $response = $this->client->attach(
            'image_file', $image->rawContent(), $image->filename() ?: 'image', ['Content-Type' => $image->mimeType()]
        )->post('https://clipdrop-api.co/image-upscaling/v1/upscale', [
            'target_height' => $request->prompt() ?: 4096,
            'target_width' => $request->prompt() ?: 4096,
        ]);

        return $this->buildResponse($response);
    }

    protected function buildResponse(ClientResponse $response): Response
    {
        $image = $this->validate($response);
        $responseBuilder = new ResponseBuilder(
            usage: new Usage(0, (int) ($response->header('x-credits-consumed') ?: 0)),
            meta: new Meta('', ''),
            images: [
                GeneratedImage::fromRawContent($image, $response->header('Content-Type')),
            ]
        );

        return $responseBuilder->toResponse();
    }

    protected function validate(ClientResponse $response): string
    {
        if ($response->status() === 200) {
            return $response->body();
        }

        throw PrismException::providerResponseError(vsprintf(
            'ClipDrop Error: [%s] %s',
            [
                $response->status(),
                data_get($response->json(), 'error', 'unknown'),
            ]
        ));
    }
}
