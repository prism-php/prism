<?php

declare(strict_types=1);

namespace Prism\Prism\File;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\ConfiguresClient;
use Prism\Prism\Concerns\ConfiguresFile;
use Prism\Prism\Concerns\ConfiguresProviders;
use Prism\Prism\Concerns\ConfiguresStorage;
use Prism\Prism\Concerns\HasProviderOptions;

class PendingRequest
{
    use ConfiguresClient;
    use ConfiguresFile;
    use ConfiguresProviders;
    use ConfiguresStorage;
    use HasProviderOptions;

    public function uploadFile(): Response
    {
        $request = $this->toRequest();
        try {
            return $this->provider->uploadFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function downloadFile(): string
    {
        $request = $this->toRequest();
        try {
            return $this->provider->downloadFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function retrieveFile(): Response
    {
        $request = $this->toRequest();
        try {
            return $this->provider->retrieveFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function listFiles(): ListResponse
    {
        $request = $this->toRequest();
        try {
            return $this->provider->listFiles($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function deleteFile(): DeleteResponse
    {

        $request = $this->toRequest();
        try {
            return $this->provider->deleteFile($request);
        } catch (RequestException $e) {
            $this->provider->handleRequestException($request->model(), $e);
        }
    }

    public function toRequest(): Request
    {
        return new Request(
            model: $this->model,
            clientOptions: $this->clientOptions,
            clientRetry: $this->clientRetry,
            purpose: $this->purpose,
            fileName: $this->fileName,
            disk: $this->disk,
            path: $this->path,
            fileOutputId: $this->fileOutputId,
            providerOptions: $this->providerOptions
        );
    }
}
