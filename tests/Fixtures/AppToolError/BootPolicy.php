<?php

declare(strict_types=1);

namespace Tests\Fixtures\AppToolError;

use Illuminate\Support\ServiceProvider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use RuntimeException;
use Throwable;

class BootPolicy extends ServiceProvider
{
    public function boot(): void
    {
        $policy = config('probe.policy');
        if ($policy === 'runtime-only') {
            return;
        }
        Prism::handleToolErrorsUsing(function (Throwable $error, Tool $tool, array $params) use ($policy): mixed {
            if ($policy === 'throws') {
                throw new RuntimeException('synthetic-callback-secret');
            }
            if ($policy === 'non-string') {
                return ['secret' => 'synthetic-callback-secret'];
            }
            if ($tool->name() !== 'probe' || $params !== ['query' => 'public-input'] || $error->getMessage() !== 'synthetic-provider-billing-secret') {
                throw new RuntimeException('Unexpected callback input.');
            }

            return 'Safe app failure from process '.getmypid().'.';
        });
    }
}
