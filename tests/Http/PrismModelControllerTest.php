<?php

declare(strict_types=1);

namespace Tests\Http;

use Illuminate\Testing\TestResponse;
use Prism\Prism\Facades\PrismServer;
use Prism\Prism\Text\Generator;

it('it returns prisms', function (): void {
    PrismServer::register('nyx', fn (): \Prism\Prism\Text\Generator => new Generator);
    PrismServer::register('omni', fn (): \Prism\Prism\Text\Generator => new Generator);

    /** @var TestResponse */
    $response = $this->getJson('/prism/openai/v1/models');

    $response->assertOk();

    $response->assertJson([
        'object' => 'list',
        'data' => [
            ['object' => 'model', 'id' => 'nyx'],
            ['object' => 'model', 'id' => 'omni'],
        ],
    ]);
});

it('handles when there are no registered prism', function (): void {
    /** @var TestResponse */
    $response = $this->getJson('/prism/openai/v1/models');

    $response->assertOk();

    $response->assertJson([
        'object' => 'list',
        'data' => [],
    ]);
});
