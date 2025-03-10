<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Contracts\Message;

trait HasMessages
{
    /** @var array<int, Message> */
    protected array $messages = [];

    /**
     * @param  array<int, Message>  $messages
     */
    public function withMessages(array $messages): self
    {
        $this->messages = $messages;

        return $this;
    }
}
