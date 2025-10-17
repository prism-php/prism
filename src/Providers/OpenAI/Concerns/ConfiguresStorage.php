<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

trait ConfiguresStorage
{
    protected string $disk;

    protected string $path;

    private function initializeDefaultConfiguresStorageTrait(): void
    {
        $this->disk = config('prism.open_ai_file_storage.disk');
        $this->path = config('prism.open_ai_file_storage.directory');
    }

    public function withDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function withPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }
}
