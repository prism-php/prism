<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

it('decodes little endian float32 embeddings and preserves response metadata', function (string $provider, bool $encoded): void {
    // Fixed IEEE-754 bytes, independent of the decoder and host byte order.
    $vector = [1.0, -2.5, 0.5, 0.0];
    $embedding = $encoded ? base64_encode(hex2bin('0000803f000020c00000003f00000000')) : $vector;
    $raw = [
        'id' => 'embedding_id', 'model' => 'embedding-model',
        'data' => [['index' => 0, 'embedding' => $embedding], ['index' => 1, 'embedding' => $embedding]],
        'usage' => ['total_tokens' => 7],
    ];
    Http::fake(['*/embeddings' => Http::response($raw)])->preventStrayRequests();

    $response = Prism::embeddings()->using($provider, 'embedding-model')
        ->withProviderOptions(['encoding_format' => $encoded ? 'base64' : 'float'])
        ->fromArray(['First', 'Second'])->asEmbeddings();

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[0]->embedding)->toEqual($vector)
        ->and($response->embeddings[1]->embedding)->toEqual($vector)
        ->and(array_keys($response->embeddings[0]->embedding))->toBe([0, 1, 2, 3])
        ->and($response->meta->model)->toBe('embedding-model')
        ->and($response->usage->tokens)->toBe(7)
        ->and($response->raw)->toEqual($raw);
    Http::assertSent(fn ($request): bool => $request['encoding_format'] === ($encoded ? 'base64' : 'float')
        && $request['input'] === ['First', 'Second']);
})->with(['openai', 'mistral'])->with([true, false]);

it('rejects untrustworthy encoded embeddings with a PrismException', function (string $provider, mixed $embedding): void {
    Http::fake(['*/embeddings' => Http::response([
        'model' => 'embedding-model', 'data' => [['embedding' => $embedding]], 'usage' => ['total_tokens' => 1],
    ])])->preventStrayRequests();

    try {
        Prism::embeddings()->using($provider, 'embedding-model')->fromInput('Test')->asEmbeddings();
        test()->fail('An invalid embedding must throw.');
    } catch (PrismException $exception) {
        expect($exception->getMessage())->toStartWith('Invalid embedding:')
            ->and($exception->responseBody)->toBeNull();
    }
})->with(['openai', 'mistral'])->with([
    'invalid alphabet' => ['private!not-base64'],
    'invalid padding' => ['AAAAAA==='],
    'nonzero padding bits' => ['AAAAAB=='],
    'empty' => [''],
    'one byte' => ['AA=='],
    'three bytes' => ['AAAA'],
    'trailing partial float' => [base64_encode(hex2bin('0000803f00'))],
    'nan' => [base64_encode(hex2bin('0000c07f'))],
    'positive infinity' => [base64_encode(hex2bin('0000807f'))],
    'negative infinity' => [base64_encode(hex2bin('000080ff'))],
    'null' => [null],
    'integer' => [42],
    'boolean' => [false],
]);

it('leaves default float embedding requests unchanged', function (string $provider): void {
    Http::fake(['*/embeddings' => Http::response([
        'model' => 'embedding-model', 'data' => [['embedding' => [0.25, -0.5]]], 'usage' => ['total_tokens' => 1],
    ])])->preventStrayRequests();

    $response = Prism::embeddings()->using($provider, 'embedding-model')->fromInput('Test')->asEmbeddings();
    expect($response->embeddings[0]->embedding)->toBe([0.25, -0.5]);
    Http::assertSent(fn ($request): bool => ! array_key_exists('encoding_format', $request->data()));
})->with(['openai', 'mistral']);

it('matches the reference embedding decoding corpus through both provider handlers', function (string $provider, array $case): void {
    Http::fake(['*/embeddings' => Http::response([
        'model' => 'embedding-model', 'data' => [['embedding' => $case['embedding']]], 'usage' => ['total_tokens' => 1],
    ])])->preventStrayRequests();
    try {
        $response = Prism::embeddings()->using($provider, 'embedding-model')->fromInput('Test')->asEmbeddings();
        $actual = ['embedding' => $response->embeddings[0]->embedding];
    } catch (PrismException $exception) {
        $actual = ['exception' => $exception::class];
    }
    expect($actual)->toEqual($case['expected']['php']);
})->with(['openai', 'mistral'])->with(function (): array {
    $corpus = json_decode(file_get_contents(__DIR__.'/../Fixtures/embedding-decoding.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_map(fn (array $case): array => [$case], $corpus['cases']);
});
