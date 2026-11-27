<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;

class EmbeddingDecoder
{
    public static function decode(#[\SensitiveParameter] mixed $embedding): Embedding
    {
        if (is_array($embedding)) {
            return Embedding::fromArray($embedding);
        }

        if (! is_string($embedding)) {
            throw new PrismException('Invalid embedding: expected a vector or base64 string.');
        }

        $bytes = base64_decode($embedding, strict: true);

        // Strict decoding alone accepts nonzero padding bits and whitespace.
        // Require canonical base64 so corrupted encodings are not normalised.
        if ($bytes === false || base64_encode($bytes) !== $embedding) {
            throw new PrismException('Invalid embedding: malformed base64.');
        }

        if ($bytes === '' || strlen($bytes) % 4 !== 0) {
            throw new PrismException('Invalid embedding: expected complete float32 values.');
        }

        // g is explicitly little endian; f would depend on the host byte order.
        $values = unpack('g*', $bytes);

        if ($values === false) {
            throw new PrismException('Invalid embedding: float32 decoding failed.');
        }

        foreach ($values as $value) {
            if (! is_finite($value)) {
                throw new PrismException('Invalid embedding: non-finite float32 value.');
            }
        }

        return Embedding::fromArray(array_values($values));
    }
}
