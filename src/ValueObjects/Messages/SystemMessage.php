<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;

/**
 * @implements Arrayable<string, mixed>
 */
class SystemMessage implements Arrayable, Message
{
    use HasProviderOptions;

    public function __construct(
        public readonly string $content
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'system',
            'content' => $this->content,
        ];
    }
}
