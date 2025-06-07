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

    protected ?Dispatcher $dispatcher;

    protected array $globalMiddleware = [];

    protected Closure|array $globalOptions = [];

    protected Collection $stubCallbacks;

    protected bool $recording = false;

    protected array $recorded = [];

    protected array $responseSequences = [];

    protected bool $preventStrayRequests = false;

    public function __construct(?Dispatcher $dispatcher = null)
    {
        $this->dispatcher = $dispatcher;
        $this->stubCallbacks = new Collection;
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

    public function globalOptions(Closure|array $options): static
    {
        $this->globalOptions = $options;

        return $this;
    }

    public static function response(array|string|null $body = null, int $status = 200, array $headers = []): PromiseInterface
    {
        if (is_array($body)) {
            $body = json_encode($body);
            $headers['Content-Type'] = 'application/json';
        }

        $response = new Psr7Response($status, $headers, $body);

        return Create::promiseFor($response);
    }

    public static function failedConnection(?string $message = null): Closure
    {
        return function ($request) use ($message) {
            return Create::rejectionFor(new ConnectException(
                $message ?? "cURL error 6: Could not resolve host: {$request->toPsrRequest()->getUri()->getHost()} (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$request->toPsrRequest()->getUri()}.",
                $request->toPsrRequest(),
            ));
        };
    }

    public function sequence(array $responses = []): ResponseSequence
    {
        return $this->responseSequences[] = new ResponseSequence($responses);
    }

    public function fake(callable|array|null $callback = null): static
    {
        $this->record();
        $this->recorded = [];

        if (is_null($callback)) {
            $callback = function () {
                return static::response();
            };
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
                    $response = $response->wait();
                }

                return $response;
            },
        ]));

        return $this;
    }

    public function fakeSequence(string $url = '*'): ResponseSequence
    {
        return tap($this->sequence(), function ($sequence) use ($url) {
            $this->fake([$url => $sequence]);
        });
    }

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
                return static::response($callback);
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

    protected function record(): static
    {
        $this->recording = true;

        return $this;
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

    public function assertSentInOrder(array $callbacks): void
    {
        $this->assertSentCount(count($callbacks));

        foreach ($callbacks as $index => $callback) {
            $this->assertSent(function ($request, $response) use ($callback, $index) {
                return $callback($request, $response) && $this->recorded()->keys()->search([$request, $response]) === $index;
            });
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

    public function recorded(?callable $callback = null): Collection
    {
        if (empty($this->recorded)) {
            return collect();
        }

        $callback = $callback ?: function () {
            return true;
        };

        return collect($this->recorded)->filter(function ($pair) use ($callback) {
            return $callback($pair[0], $pair[1]);
        });
    }

    public function createPendingRequest(): PendingRequest
    {
        return new PendingRequest($this, $this->globalMiddleware)
            ->withOptions($this->resolveGlobalOptions());
    }

    protected function resolveGlobalOptions(): array
    {
        return is_callable($this->globalOptions)
            ? ($this->globalOptions)()
            : $this->globalOptions;
    }

    public function getDispatcher(): ?Dispatcher
    {
        return $this->dispatcher;
    }

    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    public function getStubCallbacks(): Collection
    {
        return $this->stubCallbacks;
    }

    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->createPendingRequest()->{$method}(...$parameters);
    }
}
