<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use LogicException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Provider;
use RuntimeException;
use Throwable;

/**
 * Exercise request dispatch, including inherited methods and lazy streams.
 * This proves local integration support, not a live model's capabilities or
 * response parsing. Only our HTTP sentinel or the explicit unsupported error
 * decides the result; broken fixtures and unrelated errors fail the test.
 */
final class CapabilityProbe
{
    public static function implemented(callable $invoke, string $provider, string $method): bool
    {
        $originalHttp = Http::getFacadeRoot();
        $transport = new RuntimeException('Documentation probe reached HTTP');
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(fn () => throw $transport);
        Http::swap($http);

        try {
            $result = $invoke();
            if ($result instanceof Generator) {
                foreach ($result as $event) {
                    // A generator may refuse only when iteration starts.
                }
            }
        } catch (Throwable $error) {
            for ($cause = $error; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                if ($cause === $transport) {
                    return true;
                }
            }

            $unsupported = [PrismException::unsupportedProviderAction($method, $provider)->getMessage()];
            if ($method === 'stream') {
                $unsupported[] = PrismException::unsupportedProviderAction(Provider::class.'::stream', $provider)->getMessage();
            }

            if ($error instanceof PrismException && in_array($error->getMessage(), $unsupported, true)) {
                return false;
            }

            throw $error;
        } finally {
            Http::swap($originalHttp);
        }

        throw new LogicException("{$provider}::{$method} neither dispatched HTTP nor explicitly refused");
    }
}
