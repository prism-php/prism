<?php

declare(strict_types=1);

namespace Prism\Prism\Models;

/** Basic provider-returned model metadata. Dates retain the provider's representation. */
class Model
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $displayName = null,
        public readonly string|int|null $createdAt = null,
    ) {}
}
