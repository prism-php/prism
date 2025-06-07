<?php

declare(strict_types=1);

namespace Prism\Prism\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Prism\Http\Factory;

/**
 * @method static \Prism\Prism\Http\PendingRequest baseUrl(string $url)
 * @method static \Prism\Prism\Http\PendingRequest withBody(\Psr\Http\Message\StreamInterface|string $content, string $contentType = 'application/json')
 * @method static \Prism\Prism\Http\PendingRequest asJson()
 * @method static \Prism\Prism\Http\PendingRequest asForm()
 * @method static \Prism\Prism\Http\PendingRequest attach(string $name, string $contents = '', string|null $filename = null, array $headers = [])
 * @method static \Prism\Prism\Http\PendingRequest asMultipart()
 * @method static \Prism\Prism\Http\PendingRequest bodyFormat(string $format)
 * @method static \Prism\Prism\Http\PendingRequest withQueryParameters(array $parameters)
 * @method static \Prism\Prism\Http\PendingRequest contentType(string $contentType)
 * @method static \Prism\Prism\Http\PendingRequest acceptJson()
 * @method static \Prism\Prism\Http\PendingRequest accept(string $contentType)
 * @method static \Prism\Prism\Http\PendingRequest withHeaders(array $headers)
 * @method static \Prism\Prism\Http\PendingRequest withHeader(string $name, string $value)
 * @method static \Prism\Prism\Http\PendingRequest replaceHeaders(array $headers)
 * @method static \Prism\Prism\Http\PendingRequest withBasicAuth(string $username, string $password)
 * @method static \Prism\Prism\Http\PendingRequest withDigestAuth(string $username, string $password)
 * @method static \Prism\Prism\Http\PendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static \Prism\Prism\Http\PendingRequest withUserAgent(string $userAgent)
 * @method static \Prism\Prism\Http\PendingRequest withUrlParameters(array $parameters = [])
 * @method static \Prism\Prism\Http\PendingRequest withCookies(array $cookies, string $domain)
 * @method static \Prism\Prism\Http\PendingRequest maxRedirects(int $max)
 * @method static \Prism\Prism\Http\PendingRequest withoutRedirecting()
 * @method static \Prism\Prism\Http\PendingRequest withoutVerifying()
 * @method static \Prism\Prism\Http\PendingRequest sink(string $to)
 * @method static \Prism\Prism\Http\PendingRequest timeout(int|float $seconds)
 * @method static \Prism\Prism\Http\PendingRequest connectTimeout(int|float $seconds)
 * @method static \Prism\Prism\Http\PendingRequest retry(array|int $times, \Closure|int $sleepMilliseconds = 0, callable|null $when = null, bool $throw = true)
 * @method static \Prism\Prism\Http\PendingRequest withOptions(array $options)
 * @method static \Prism\Prism\Http\PendingRequest withMiddleware(callable $middleware)
 * @method static \Prism\Prism\Http\PendingRequest withRequestMiddleware(callable $middleware)
 * @method static \Prism\Prism\Http\PendingRequest withResponseMiddleware(callable $middleware)
 * @method static \Prism\Prism\Http\PendingRequest withProvider(string $provider)
 * @method static \Prism\Prism\Http\PendingRequest withRateLimit(int $requestsPerMinute)
 * @method static \Prism\Prism\Http\PendingRequest withPrismOptions(array $options)
 * @method static \Prism\Prism\Http\Response get(string $url, array|string|null $query = null)
 * @method static \Prism\Prism\Http\Response head(string $url, array|string|null $query = null)
 * @method static \Prism\Prism\Http\Response post(string $url, array $data = [])
 * @method static \Prism\Prism\Http\Response patch(string $url, array $data = [])
 * @method static \Prism\Prism\Http\Response put(string $url, array $data = [])
 * @method static \Prism\Prism\Http\Response delete(string $url, array $data = [])
 * @method static \Prism\Prism\Http\Response send(string $method, string $url, array $options = [])
 * @method static \Prism\Prism\Http\Factory globalMiddleware(callable $middleware)
 * @method static \Prism\Prism\Http\Factory globalRequestMiddleware(callable $middleware)
 * @method static \Prism\Prism\Http\Factory globalResponseMiddleware(callable $middleware)
 * @method static \Prism\Prism\Http\Factory globalOptions(\Closure|array $options)
 * @method static \GuzzleHttp\Promise\PromiseInterface response(array|string|null $body = null, int $status = 200, array $headers = [])
 * @method static \Closure failedConnection(string|null $message = null)
 * @method static \Prism\Prism\Http\ResponseSequence sequence(array $responses = [])
 * @method static \Prism\Prism\Http\Factory fake(callable|array|null $callback = null)
 * @method static \Prism\Prism\Http\ResponseSequence fakeSequence(string $url = '*')
 * @method static \Prism\Prism\Http\Factory stubUrl(string $url, \Prism\Prism\Http\Response|\GuzzleHttp\Promise\PromiseInterface|callable|int|string|array $callback)
 * @method static \Prism\Prism\Http\Factory preventStrayRequests(bool $prevent = true)
 * @method static bool preventingStrayRequests()
 * @method static \Prism\Prism\Http\Factory allowStrayRequests()
 * @method static void recordRequestResponsePair(\Prism\Prism\Http\Request $request, \Prism\Prism\Http\Response|null $response)
 * @method static void assertSent(callable $callback)
 * @method static void assertSentInOrder(array $callbacks)
 * @method static void assertNotSent(callable $callback)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 * @method static void assertSequencesAreEmpty()
 * @method static \Illuminate\Support\Collection recorded(callable|null $callback = null)
 * @method static \Prism\Prism\Http\PendingRequest createPendingRequest()
 * @method static \Illuminate\Contracts\Events\Dispatcher|null getDispatcher()
 * @method static array getGlobalMiddleware()
 * @method static \Illuminate\Support\Collection getStubCallbacks()
 *
 * @see \Prism\Prism\Http\Factory
 */
class Http extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Factory::class;
    }
}
