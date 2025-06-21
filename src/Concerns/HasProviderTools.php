<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\ValueObjects\ProviderTool;

trait HasProviderTools
{
    /** @var array<int,ProviderTool> */
    protected array $providerTools = [];

    /**
     * @param  array<int,ProviderTool>  $providerTools
     */
    public function withProviderTools(array $providerTools): self
    {
        $this->providerTools = $providerTools;

        return $this;
    }
}
