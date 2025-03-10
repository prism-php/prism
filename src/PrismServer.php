<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Support\Collection;
use Prism\Prism\Text\PendingRequest;

readonly class PrismServer
{
    /**
     * @param  Collection<int, array{name: string, prism: Closure():PendingRequest|callable():PendingRequest}>  $prisms
     * */
    public function __construct(
        protected Collection $prisms = new Collection,
    ) {}

    /** @param \Closure():PendingRequest|callable():PendingRequest $prism */
    public function register(string $name, Closure|callable $prism): self
    {
        $this->prisms->push(['name' => $name, 'prism' => $prism]);

        return $this;
    }

    /** @return Collection<int, array{name: string, prism: Closure():PendingRequest|callable():PendingRequest}> */
    public function prisms(): Collection
    {
        return $this->prisms;
    }
}
