<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @mixin \Prism\Prism\Http\PendingRequest
 */
class Factory
{
    use Macroable {
        __call as macroCall;
    }

    /** @var array<callable> */
    protected array $globalMiddleware = [];

    /** @var Closure|array<string, mixed> */
    protected Closure|array $globalOptions = [];

    /** @var Collection<int, callable> */
    protected Collection $stubCallbacks;

    protected bool $recording = false;

    /** @var array<array{Request, Response|null}> */
    protected array $recorded = [];

    /** @var array<ResponseSequence> */
    protected array $responseSequences = [];

    protected bool $preventStrayRequests = false;

    public function __construct(protected ?Dispatcher $dispatcher = null)
    {
        $this->stubCallbacks = new Collection;
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->createPendingRequest()->{$method}(...$parameters);
    }

    public function globalMiddleware(callable $middleware): static
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    public function globalRequestMiddleware(callable $middleware): static
    {
        $this->globalMiddleware[] = Middleware::mapRequest($middleware);

        return $this;
    }

    public function globalResponseMiddleware(callable $middleware): static
    {
        $this->globalMiddleware[] = Middleware::mapResponse($middleware);

        return $this;
    }

    /**
     * @param  Closure|array<string, mixed>  $options
     */
    public function globalOptions(Closure|array $options): static
    {
        $this->globalOptions = $options;

        return $this;
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     * @param  array<string, string>  $headers
     */
    public static function response(array|string|null $body = null, int $status = 200, array $headers = []): PromiseInterface
    {
        if (is_array($body)) {
            $body = json_encode($body) ?: '{}';
            $headers['Content-Type'] = 'application/json';
        }

        $response = new Psr7Response($status, $headers, $body);

        return Create::promiseFor($response);
    }

    public static function failedConnection(?string $message = null): Closure
    {
        return fn ($request): \GuzzleHttp\Promise\PromiseInterface => Create::rejectionFor(new ConnectException(
            $message ?? "cURL error 6: Could not resolve host: {$request->toPsrRequest()->getUri()->getHost()} (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$request->toPsrRequest()->getUri()}.",
            $request->toPsrRequest(),
        ));
    }

    /**
     * @param  array<mixed>  $responses
     */
    public function sequence(array $responses = []): ResponseSequence
    {
        return $this->responseSequences[] = new ResponseSequence($responses);
    }

    /**
     * @param  callable|array<string, mixed>|null  $callback
     */
    public function fake(callable|array|null $callback = null): static
    {
        $this->record();
        $this->recorded = [];

        if (is_null($callback)) {
            $callback = fn (): \GuzzleHttp\Promise\PromiseInterface => static::response();
        }

        if (is_array($callback)) {
            foreach ($callback as $url => $callable) {
                $this->stubUrl($url, $callable);
            }

            return $this;
        }

        $this->stubCallbacks = $this->stubCallbacks->merge(new Collection([
            function ($request, $options) use ($callback) {
                $response = $callback;

                while ($response instanceof Closure) {
                    $response = $response($request, $options);
                }

                if ($response instanceof PromiseInterface) {
                    return $response->wait();
                }

                return $response;
            },
        ]));

        return $this;
    }

    public function fakeSequence(string $url = '*'): ResponseSequence
    {
        return tap($this->sequence(), function ($sequence) use ($url): void {
            $this->fake([$url => $sequence]);
        });
    }

    /**
     * @param  Response|PromiseInterface|callable|int|string|array<string, mixed>  $callback
     */
    public function stubUrl(string $url, Response|PromiseInterface|callable|int|string|array $callback): static
    {
        return $this->fake(function ($request, $options) use ($url, $callback) {
            if (! Str::is(Str::start($url, '*'), $request->url())) {
                return null;
            }

            if (is_int($callback) && $callback >= 100 && $callback < 600) {
                return static::response(status: $callback);
            }

            if (is_int($callback) || is_string($callback)) {
                return static::response((string) $callback);
            }

            if ($callback instanceof Closure || $callback instanceof ResponseSequence) {
                return $callback($request, $options);
            }

            return $callback;
        });
    }

    public function preventStrayRequests(bool $prevent = true): static
    {
        $this->preventStrayRequests = $prevent;

        return $this;
    }

    public function preventingStrayRequests(): bool
    {
        return $this->preventStrayRequests;
    }

    public function allowStrayRequests(): static
    {
        return $this->preventStrayRequests(false);
    }

    public function recordRequestResponsePair(Request $request, ?Response $response): void
    {
        if ($this->recording) {
            $this->recorded[] = [$request, $response];
        }
    }

    public function assertSent(callable $callback): void
    {
        PHPUnit::assertTrue(
            $this->recorded($callback)->count() > 0,
            'An expected request was not recorded.'
        );
    }

    /**
     * @param  array<callable>  $callbacks
     */
    public function assertSentInOrder(array $callbacks): void
    {
        $this->assertSentCount(count($callbacks));

        foreach ($callbacks as $index => $callback) {
            $this->assertSent(fn ($request, $response): bool => $callback($request, $response) && array_search([$request, $response], $this->recorded, true) === $index);
        }
    }

    public function assertNotSent(callable $callback): void
    {
        PHPUnit::assertFalse(
            $this->recorded($callback)->count() > 0,
            'An unexpected request was recorded.'
        );
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            'No requests were expected to be recorded.'
        );
    }

    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->recorded);
    }

    public function assertSequencesAreEmpty(): void
    {
        $sequences = collect($this->responseSequences)->filter->isEmpty();

        PHPUnit::assertEmpty(
            $sequences,
            'Not all response sequences are empty.'
        );
    }

    /**
     * @return Collection<int, array{Request, Response|null}>
     */
    public function recorded(?callable $callback = null): Collection
    {
        if ($this->recorded === []) {
            return collect();
        }

        $callback = $callback ?: fn (): true => true;

        return collect($this->recorded)->filter(fn ($pair) => $callback($pair[0], $pair[1]));
    }

    public function createPendingRequest(): PendingRequest
    {
        return (new PendingRequest($this, $this->globalMiddleware))
            ->withOptions($this->resolveGlobalOptions());
    }

    public function getDispatcher(): ?Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * @return array<callable>
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * @return Collection<int, callable>
     */
    public function getStubCallbacks(): Collection
    {
        return $this->stubCallbacks;
    }

    protected function record(): static
    {
        $this->recording = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveGlobalOptions(): array
    {
        return is_callable($this->globalOptions)
            ? ($this->globalOptions)()
            : $this->globalOptions;
    }
}
