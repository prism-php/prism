<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

class PrismStructuredDecodingException extends PrismException
{
    public function __construct(string $responseText)
    {
        parent::__construct(sprintf(
            'Structured object could not be decoded. Received: %s',
            $responseText
        ));
    }

    public static function make(string $responseText): self
    {
        return new self($responseText);
    }
}
