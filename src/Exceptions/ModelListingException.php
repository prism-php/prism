<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

/** Listing errors never retain provider response bodies, request URLs or credentials. */
class ModelListingException extends PrismException
{
    public static function unsupported(string $provider): self
    {
        return new UnsupportedModelListingException("Model listing is not supported for {$provider}.");
    }

    public static function invalidResponse(): self
    {
        return new self('The provider returned an invalid or incomplete model listing.');
    }

    public static function requestFailed(): self
    {
        return new self('The provider model listing request failed.');
    }

    public static function authenticationFailed(): self
    {
        return new ModelListingAuthenticationException('The provider rejected credentials or access for model listing.');
    }
}
