<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Prism\Prism\ValueObjects\ProviderRateLimit;

class PrismRateLimitedException extends PrismException
{
    /**
     * @param  ProviderRateLimit[]  $rateLimits
     */
    public function __construct(
        public readonly array $rateLimits,
        public readonly ?int $retryAfter = null
    ) {
        $message = 'You hit a provider rate limit';

        if ($retryAfter) {
            $message .= ' - retry after '.$retryAfter.' seconds';
        }

        $message .= '. Details: '.json_encode($rateLimits);

        parent::__construct($message);
    }

    /**
     * @param  ProviderRateLimit[]  $rateLimits
     */
    public static function make(array $rateLimits = [], ?int $retryAfter = null): self
    {
        return new self(rateLimits: $rateLimits, retryAfter: $retryAfter);
    }
}
