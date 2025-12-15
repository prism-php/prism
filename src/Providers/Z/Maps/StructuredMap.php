<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Maps;

use Prism\Prism\Contracts\Schema;
use Prism\Prism\Providers\Z\Support\ZAIJSONEncoder;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class StructuredMap extends MessageMap
{
    public function __construct(array $messages, array $systemPrompts, private readonly Schema $schema)
    {
        parent::__construct($messages, $systemPrompts);
    }

    #[\Override]
    protected function mapSystemMessage(SystemMessage $message): void
    {
        $scheme = $this->schema;

        $structured = ZAIJSONEncoder::jsonEncode($scheme);

        $this->mappedMessages[] = [
            'role' => 'system',
            'content' => <<<PROMPT
            You are a helpful AI assistant. You must respond with valid JSON that conforms to this schema:
            $structured

            === IMPORTANT ===
            - Your entire response must be valid JSON
            - Do not include any text outside the JSON structure
            - Do not add explanations or markdown formatting
            - Just return the JSON object

            === RULES ===
            $message->content
            PROMPT,
        ];
    }
}
