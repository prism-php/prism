<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

/** A refused completion, with provider content available only by explicit access. */
class PrismRefusalException extends PrismException
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $refusal,
    ) {
        parent::__construct('OpenAI: response_refused', 200);
        $this->httpStatus = 200;
    }

    public function code(): string
    {
        return 'response_refused';
    }

    /** May contain sensitive provider content. */
    public function refusal(): string
    {
        return $this->refusal;
    }
}
