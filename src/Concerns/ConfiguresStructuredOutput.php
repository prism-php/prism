<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Enums\StructuredMode;

trait ConfiguresStructuredOutput
{
    protected StructuredMode $structuredMode = StructuredMode::Auto;

    protected function usingStructuredMode(StructuredMode $mode): self
    {
        $this->structuredMode = $mode;

        return $this;
    }
}
