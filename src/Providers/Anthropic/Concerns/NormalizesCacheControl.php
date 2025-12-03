<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use BackedEnum;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

trait NormalizesCacheControl
{
    /**
     * @return array<string, mixed>|null
     */
    protected static function normalizeCacheControl(AssistantMessage|SystemMessage|Tool|ToolResultMessage|UserMessage $message): ?array
    {
        $cacheType = $message->providerOptions('cacheType');
        $cacheTtl = $message->providerOptions('cacheTtl');

        if (! $cacheType) {
            return null;
        }

        $value = $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType;

        return $cacheTtl ? ['type' => $value, 'ttl' => $cacheTtl] : ['type' => $value];
    }
}
