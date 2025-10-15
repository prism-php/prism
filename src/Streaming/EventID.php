<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Illuminate\Support\Str;

class EventID
{
    public static function generate(string $prefix = 'evt'): string
    {
        return $prefix.'_'.Str::ulid();
    }
}
