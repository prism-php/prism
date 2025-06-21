<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class ToolCall
{
    /**
     * @param  string|array<string, mixed>  $arguments
     * @param  null|array<string, mixed>  $reasoningSummary
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        protected string|array $arguments,
        public readonly ?string $resultId = null,
        public readonly ?string $reasoningId = null,
        public readonly ?array $reasoningSummary = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        if (is_string($this->arguments)) {
            if ($this->arguments === '' || $this->arguments === '0') {
                return [];
            }

            /** @var string $arguments */
            $arguments = $this->arguments;

            return json_decode(
                $arguments,
                true,
                flags: JSON_THROW_ON_ERROR
            );
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $this->arguments;

        return $arguments;
    }
}
