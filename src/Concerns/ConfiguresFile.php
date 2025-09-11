<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait ConfiguresFile
{
    protected ?string $purpose = null;

    protected ?string $fileName = null;

    protected ?string $fileOutputId = null;

    public function withPurpose(string $purpose): self
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function withFileName(string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function withFileOutputId(string $fileOutputId): self
    {
        $this->fileOutputId = $fileOutputId;

        return $this;
    }
}
