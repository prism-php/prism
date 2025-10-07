<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Concerns\ConfiguresFile;
use Prism\Prism\Providers\OpenAI\Concerns\ConfiguresStorage;
use Prism\Prism\Providers\OpenAI\Concerns\PreparesFileResponses;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\File\DeleteResponse;
use Prism\Prism\Providers\OpenAI\File\ListResponse;
use Prism\Prism\Providers\OpenAI\File\Response;

class File
{
    use ConfiguresFile;
    use ConfiguresStorage;
    use PreparesFileResponses;
    use ValidatesResponse;

    public function __construct(protected PendingRequest $client)
    {
        $this->initializeDefaultConfiguresFileTrait();
        $this->initializeDefaultConfiguresStorageTrait();
    }

    public function uploadFile(): Response
    {

        if (! $this->fileName || ! $this->path || ! $this->disk) {
            throw new PrismException('File name, disk and path are required');
        }
        $fileHandle = $this->openFile(
            $this->path.$this->fileName, 'r'
        );

        $response = $this->client
            ->attach('file', $fileHandle, $this->fileName)
            ->post(config('prism.providers.openai.files_endpoint'), [
                'purpose' => $this->purpose,
            ]);

        $this->closeFile($fileHandle);

        $this->validateResponse($response);

        if ($response->status() !== 200) {
            throw new PrismException('Failed to upload file');
        }

        return $this->prepareFileResponse($response->json());
    }

    /**
     * Returns the raw content of the file in a string
     */
    public function downloadFile(): string
    {
        if (! $this->fileOutputId) {
            throw new PrismException('File output ID is required');
        }
        $response = $this->client->get(
            config('prism.providers.openai.files_endpoint')."/{$this->fileOutputId}/content"
        );

        if ($response->status() !== 200) {
            throw new PrismException('Failed to download file');
        }

        return $response->body();
    }

    public function retrieveFile(): Response
    {
        if (! $this->fileOutputId) {
            throw new PrismException('File output ID is required');
        }
        $response = $this->client->get(config('prism.providers.openai.files_endpoint')."/{$this->fileOutputId}");

        if ($response->status() !== 200) {
            throw new PrismException('Failed to retrieve file');
        }

        return $this->prepareFileResponse($response->json());
    }

    public function listFiles(): ListResponse
    {
        $response = $this->client->get(config('prism.providers.openai.files_endpoint'));

        if ($response->status() !== 200) {
            throw new PrismException('Failed to list files');
        }

        return $this->prepareFileListResponse($response->json());
    }

    public function deleteFile(): DeleteResponse
    {
        if (! $this->fileOutputId) {
            throw new PrismException('File output ID is required');
        }
        $response = $this->client->delete(config('prism.providers.openai.files_endpoint')."/{$this->fileOutputId}");

        if ($response->status() !== 200) {
            throw new PrismException('Failed to delete file');
        }

        return $this->prepareDeleteResponse($response->json());
    }

    protected function openFile(string $absoluteFileName, string $mode)
    {
        $fileHandle = fopen(Storage::disk($this->disk)->path($absoluteFileName), $mode);
        if (! $fileHandle) {
            throw new PrismException('Failed to open file');
        }

        return $fileHandle;
    }

    protected function closeFile($fileHandle): void
    {
        fclose($fileHandle);
    }
}
