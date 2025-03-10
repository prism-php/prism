<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class ToolCall
{
    /**
     * @param  string|array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        protected string|array $arguments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        if (is_string($this->arguments)) {
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
