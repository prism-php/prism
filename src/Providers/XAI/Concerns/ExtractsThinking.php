<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Concerns;

use Prism\Prism\Text\Request;

trait ExtractsThinking
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractThinking(array $data, Request $request): string
    {
        if (data_get($request->providerOptions('thinking'), 'enabled', true) === false) {
            return '';
        }

        $reasoning = data_get($data, 'choices.0.delta.reasoning_content', '');

        if ($reasoning !== '') {
            static $lastThinkingContent = ''; // preserve state across calls

            if (str_contains((string) $lastThinkingContent, 'Thinking') && trim((string) $reasoning) === 'Thinking...') {
                return '';
            }

            $lastThinkingContent .= $reasoning;

            return $reasoning;
        }

        return data_get($data, 'choices.0.delta.thinking', '');
    }
}
