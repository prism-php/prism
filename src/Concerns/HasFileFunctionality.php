<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\File\DeleteResponse as FileDeleteResponse;
use Prism\Prism\File\ListResponse as FileListResponse;
use Prism\Prism\File\Request as FileRequest;
use Prism\Prism\File\Response as FileResponse;

trait HasFileFunctionality
{
    public function processFile(FileRequest $request): FileResponse
    {
        throw PrismException::unsupportedProviderAction('processFile', class_basename($this));
    }

    public function deleteFile(FileRequest $request): FileDeleteResponse
    {
        throw PrismException::unsupportedProviderAction('deleteFile', class_basename($this));
    }

    public function listFiles(FileRequest $request): FileListResponse
    {
        throw PrismException::unsupportedProviderAction('listFiles', class_basename($this));
    }

    public function uploadFile(FileRequest $request): FileResponse
    {
        throw PrismException::unsupportedProviderAction('uploadFile', class_basename($this));
    }

    public function downloadFile(FileRequest $request): string
    {
        throw PrismException::unsupportedProviderAction('downloadFile', class_basename($this));
    }

    public function retrieveFile(FileRequest $request): FileResponse
    {
        throw PrismException::unsupportedProviderAction('retrieveFile', class_basename($this));
    }
}
