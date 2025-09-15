<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait ConfiguresStorage
{
    protected ?string $disk = 'local';

    protected ?string $path = 'batchfiles/';

    public function withDisk(?string $disk): self
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
