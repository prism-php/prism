<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'groq/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::Groq, 'llama-3.3-70b-versatile')
        ->withSystemPrompt('The tigers game is at 3pm in Detroit, the temperature is expected to be 75º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    // Assert response type
    expect($response)->toBeInstanceOf(StructuredResponse::class);

    // Assert structured data
    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['game_time'])->toBeString()->toBe('3pm');
    expect($response->structured['weather'])->toBeString()->toBe('75º');
    expect($response->structured['coat_required'])->toBeBool()->toBeFalse();

    // Assert metadata
    expect($response->meta->id)->toBe('chatcmpl-259cad75-8b85-4980-a0db-5f64b91b1fc5');
    expect($response->meta->model)->toBe('llama-3.3-70b-versatile');
    expect($response->usage->promptTokens)->toBe(172);
    expect($response->usage->completionTokens)->toBe(26);
});

it('maps cached input and reasoning on structured responses', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-usage',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-120b',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"code":"8573"}'], 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 4641,
            'completion_tokens' => 70,
            'total_tokens' => 4711,
            'prompt_tokens_details' => ['cached_tokens' => 4608],
            'completion_tokens_details' => ['reasoning_tokens' => 59],
        ],
    ])])->preventStrayRequests();

    $response = Prism::structured()
        ->withSchema(new ObjectSchema('output', 'the output object', [new StringSchema('code', 'The code')], ['code']))
        ->using(Provider::Groq, 'openai/gpt-oss-120b')
        ->withPrompt('What is the code of Fact 17?')
        ->asStructured();

    expect($response->usage->toArray())->toBe([
        'prompt_tokens' => 33,
        'completion_tokens' => 70,
        'cache_write_input_tokens' => null,
        'cache_read_input_tokens' => 4608,
        'thought_tokens' => 59,
    ]);
});
