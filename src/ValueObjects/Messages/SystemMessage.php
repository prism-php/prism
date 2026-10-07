<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Concerns\CopiesWithProviderOptions;
use Prism\Prism\Concerns\HasCacheHint;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\DeclaresCacheStability;
use Prism\Prism\Contracts\Message;

/**
 * @implements Arrayable<string, mixed>
 */
class SystemMessage implements Arrayable, DeclaresCacheStability, Message
{
    use CopiesWithProviderOptions;
    use HasCacheHint;
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
