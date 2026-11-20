<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Support\Collection;
use Prism\Prism\Text\PendingRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

readonly class PrismServer
{
    /**
     * @param  Collection<int, array{name: string, prism: Closure():PendingRequest|callable():PendingRequest}>  $prisms
     * */
    public function __construct(
        protected Collection $prisms = new Collection,
        /** @var Collection<string, Closure> */
        protected Collection $settings = new Collection,
    ) {}

    /**
     * Decide how a Prism Server failure becomes the HTTP response (#150).
     *
     * The renderer receives the exception and returns a Response, or null to keep the default: a fixed
     * public message with the right status, never the exception's own text. What a renderer sends is the
     * app's decision. A renderer that throws falls back to the default, and both exceptions are reported.
     *
     * @param  (Closure(Throwable):(Response|null))|null  $renderer  null restores the default
     */
    public function renderErrorsUsing(?Closure $renderer): self
    {
        $renderer instanceof Closure ? $this->settings->put('errorRenderer', $renderer) : $this->settings->forget('errorRenderer');

        return $this;
    }

    /** @return (Closure(Throwable):(Response|null))|null */
    public function errorRenderer(): ?Closure
    {
        return $this->settings->get('errorRenderer');
    }

    /** @param Closure():PendingRequest|callable():PendingRequest $prism */
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
