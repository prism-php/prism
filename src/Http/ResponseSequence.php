<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

use GuzzleHttp\Promise\PromiseInterface;

class ResponseSequence
{
    protected bool $failWhenEmpty = true;

    /** @var array<mixed> */
    protected array $emptySequenceResponses = [];

    /**
     * @param  array<mixed>  $responses
     */
    public function __construct(protected array $responses = []) {}

    public function __invoke(): mixed
    {
        if ($this->failWhenEmpty && $this->isEmpty()) {
            if ($this->emptySequenceResponses !== []) {
                return array_shift($this->emptySequenceResponses);
            }

            throw new \OutOfBoundsException('A request was made, but the response sequence is empty.');
        }

        return array_shift($this->responses);
    }

    /**
     * @param  PromiseInterface|Response|callable|array<mixed>|string  $response
     */
    public function push(PromiseInterface|Response|callable|array|string $response): static
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function pushStatus(int $status, array $headers = []): static
    {
        return $this->push(Factory::response('', $status, $headers));
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    public function pushResponse(array|string $body = '', int $status = 200, array $headers = []): static
    {
        return $this->push(Factory::response($body, $status, $headers));
    }

    /**
     * @param  PromiseInterface|Response|callable|array<mixed>|string  $response
     */
    public function whenEmpty(PromiseInterface|Response|callable|array|string $response): static
    {
        $this->emptySequenceResponses[] = $response;

        return $this;
    }

    public function dontFailWhenEmpty(): static
    {
        $this->failWhenEmpty = false;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->responses === [];
    }
}
