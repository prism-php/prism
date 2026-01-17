<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Events;

use Prism\Prism\Enums\StreamEventType;

readonly class StepFinishEvent extends StreamEvent
{
    public function type(): StreamEventType
    {
        return StreamEventType::StepFinish;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
        ];
    }
}
