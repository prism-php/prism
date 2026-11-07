<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
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

it('maps prompt_eval_cached_count on structured responses', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'qwen3:14b',
        'created_at' => '2026-10-15T17:58:57.192416Z',
        'message' => ['role' => 'assistant', 'content' => '{"answer":"Hi"}'],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 40,
        'prompt_eval_cached_count' => 32,
        'eval_count' => 9,
    ])])->preventStrayRequests();

    $response = Prism::structured()
        ->using('ollama', 'qwen3:14b')
        ->withSchema(new ObjectSchema('output', 'the output', [new StringSchema('answer', 'The answer')], ['answer']))
        ->withPrompt('Hi')
        ->asStructured();

    expect($response->usage->promptTokens)->toBe(8)
        ->and($response->usage->cacheReadInputTokens)->toBe(32)
        ->and($response->usage->completionTokens)->toBe(9);
});
