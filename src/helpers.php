<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Prism\Prism\Prism;

if (! function_exists('prism')) {

    /**
     * A fluent helper function to resolve prism from
     * the application container.
     */
    function prism(): Prism
    {
        return App::make(Prism::class);
    }
}

if (! function_exists('now_nanos')) {

    /**
     * Get the current Unix timestamp in nanoseconds.
     */
    function now_nanos(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }
}
