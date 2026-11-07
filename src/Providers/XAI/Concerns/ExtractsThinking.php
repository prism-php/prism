<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Concerns;

use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;

/**
 * @property StreamState $state
 */
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
            $stepThinking = $this->state->currentThinking();

            if (str_contains((string) $stepThinking, 'Thinking') && trim((string) $reasoning) === 'Thinking...') {
                return '';
            }

            $this->state->withThinking($stepThinking.$reasoning);

            return $reasoning;
        }

        return data_get($data, 'choices.0.delta.thinking', '');
    }
}
