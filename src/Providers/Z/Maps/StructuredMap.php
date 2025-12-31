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

        $this->messages[] = new SystemMessage(
            content: 'Response Format in JSON following:'.ZAIJSONEncoder::jsonEncode($this->schema)
        );
    }
}
