<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

it('Z provider handles structured request', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'z/structured-basic-response');

    $schema = new ObjectSchema(
        'person',
        'A person object',
        [
            new StringSchema('name', 'The person\'s name'),
        ],
        ['name']
    );

    $response = Prism::structured()
        ->using(Provider::Z, 'glm-4.6')
        ->withSchema($schema)
        ->asStructured();

    expect($response->text)->toBe('{"name": "John", "age": 30}')
        ->and($response->structured)->toBe(['name' => 'John', 'age' => 30])
        ->and($response->usage->promptTokens)->toBe(9)
        ->and($response->usage->completionTokens)->toBe(12)
        ->and($response->meta->id)->toBe('chatcmpl-123')
        ->and($response->meta->model)->toBe('z-model');
});
