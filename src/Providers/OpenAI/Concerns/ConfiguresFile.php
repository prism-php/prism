<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

trait ConfiguresFile
{
    protected string $purpose;

    protected ?string $fileName = null;

    protected ?string $fileOutputId = null;

    private function initializeDefaultConfiguresFileTrait(): void
    {
        $this->purpose = config('prism.open_ai_file_storage.file_purpose');
    }

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
