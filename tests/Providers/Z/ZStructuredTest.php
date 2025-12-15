<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Providers\Z\Z;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\TestStructuredRequest;

test('Z provider handles structured request', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1677652288,
            'model' => 'z-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"name": "John", "age": 30}',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 9,
                'completion_tokens' => 12,
                'total_tokens' => 21,
            ],
        ]),
    ]);

    $provider = new Z('test-api-key', 'https://api.z.ai/v1');
    $schema = new ObjectSchema(
        'person',
        'A person object',
        [
            new StringSchema('name', 'The person\'s name'),
        ],
        ['name']
    );
    $request = new TestStructuredRequest(schema: $schema);

    $response = $provider->structured($request);

    expect($response->text)->toBe('{"name": "John", "age": 30}');
    expect($response->structured)->toBe(['name' => 'John', 'age' => 30]);
    expect($response->usage->promptTokens)->toBe(9);
    expect($response->usage->completionTokens)->toBe(12);
    expect($response->meta->id)->toBe('chatcmpl-123');
    expect($response->meta->model)->toBe('z-model');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['model'] === 'test-model' &&
               $data['response_format']['type'] === 'json_object' &&
               $data['thinking']['type'] === 'disabled';
    });
});
