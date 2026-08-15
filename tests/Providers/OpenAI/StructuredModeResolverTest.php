<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;

it('resolves GPT-5.6 Luna to strict structured mode', function (): void {
    expect(StructuredModeResolver::forModel('gpt-5.6-luna'))
        ->toBe(StructuredMode::Structured);
});
