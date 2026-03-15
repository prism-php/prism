<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Handlers\Files;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Files\DeleteFileRequest;
use Prism\Prism\Files\DeleteFileResult;
use Prism\Prism\Providers\Anthropic\Concerns\HandlesFileResponse;

/**
 * @see https://docs.anthropic.com/en/api/files-delete
 */
class Delete
{
    use HandlesFileResponse;

    public function __construct(
        protected PendingRequest $client,
    ) {}

    public function handle(DeleteFileRequest $request): DeleteFileResult
    {
        $response = $this->client->delete("files/{$request->fileId}");

        $data = $response->json();
        $this->handleResponseErrors($data);

        return new DeleteFileResult(
            id: data_get($data, 'id', ''),
            deleted: data_get($data, 'type') === 'file_deleted',
        );
    }
}
