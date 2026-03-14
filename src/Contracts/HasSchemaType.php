<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

interface HasSchemaType
{
    public function schemaType(): string;
}
