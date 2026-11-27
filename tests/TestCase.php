<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestcase;

abstract class TestCase extends BaseTestcase
{
    use WithWorkbench;

    /**
     * Loopback servers the suite starts itself (guarded-fetch, streaming and
     * redirect fixtures). Every other host is off limits to every test.
     */
    public const LOOPBACK_URLS = ['http://127.0.0.1*', 'https://127.0.0.1*', 'http://localhost*', 'https://localhost*', 'http://[::1]*', 'https://[::1]*'];

    /**
     * No test reaches the network. A request through the HTTP client that no
     * fake answers throws, unless it goes to a loopback server; requests that
     * bypass the client (Guzzle or curl directly) are sent to a closed local
     * proxy by phpunit.xml.dist, so they fail instead of leaving the machine.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::allowStrayRequests(self::LOOPBACK_URLS);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('prism.prism_server.enabled', true);
    }
}
