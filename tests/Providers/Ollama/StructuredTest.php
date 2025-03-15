<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('api/chat', 'ollama/structured');

    $profile = file_get_contents('tests/Fixtures/profile.md');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('name', 'The users name'),
            new ArraySchema('hobbies', 'a list of the users hobbies',
                new StringSchema('name', 'the name of the hobby'),
            ),
            new ArraySchema('open_source', 'The users open source contributions',
                new StringSchema('name', 'the name of the project'),
            ),
        ],
        ['name', 'hobbies', 'open_source']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Ollama, 'deepseek-r1:14b-qwen-distill-q8_0')
        ->withSystemPrompt('Extract the name, hobbies, and open source projects from the users profile')
        ->withPrompt($profile)
        ->withClientOptions(['timeout' => 10000])
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'name',
        'hobbies',
        'open_source',
    ]);
    expect($response->structured['name'])->toBeString();
    expect($response->structured['hobbies'])->toBeArray();
    expect($response->structured['open_source'])->toBeArray();

});

it('throws an exception with multiple system prompts', function (): void {
    Http::preventStrayRequests();

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('name', 'The users name'),
            new ArraySchema('hobbies', 'a list of the users hobbies',
                new StringSchema('name', 'the name of the hobby'),
            ),
            new ArraySchema('open_source', 'The users open source contributions',
                new StringSchema('name', 'the name of the project'),
            ),
        ],
        ['name', 'hobbies', 'open_source']
    );

    $response = Prism::structured()
        ->using('ollama', 'qwen2.5:14b')
        ->withSchema($schema)
        ->withSystemPrompts([
            new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!'),
            new SystemMessage('But my friends call my Nyx.'),
        ])
        ->withPrompt('Who are you?')
        ->asStructured();

})->throws(PrismException::class, 'Ollama does not support multiple system prompts using withSystemPrompt / withSystemPrompts. However, you can provide additional system prompts by including SystemMessages in with withMessages.');
