<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Contracts\Schema;

trait HasSchema
{
    protected ?Schema $schema = null;

    public function withSchema(Schema $schema): self
    {
        $this->schema = $schema;

        return $this;
    }
}
