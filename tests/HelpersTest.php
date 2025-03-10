<?php

declare(strict_types=1);

use Prism\Prism\Prism;

it('can resolve prism from the container with helper function', function (): void {
    expect(prism())->toBeInstanceOf(Prism::class);
});
