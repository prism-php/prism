<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestcase;

abstract class TestCase extends BaseTestcase
{
    use WithWorkbench;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('prism.prism_server.enabled', true);
    }
}
