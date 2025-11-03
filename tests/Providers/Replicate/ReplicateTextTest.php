<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10); // Fast polling for tests
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Text generation for Replicate', function (): void {
    it('can generate text with a prompt', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/create-prediction-text.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/get-prediction-text-success.json'), true);

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            'https://api.replicate.com/v1/predictions/rrr4z55ocneqzikepnug6xezpe' => Http::response($completedResponse, 200),
        ]);

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Hello, world!')
            ->withMaxTokens(100)
            ->generate();

        expect($response->text)->toBe('Hello! How can I assist you today?')
            ->and($response->steps[0]->additionalContent['metrics']['predict_time'])->toBe(1.234567)
            ->and($response->steps[0]->additionalContent['metrics']['total_time'])->toBe(3.012345);
    });

    it('can generate text with system prompt', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/create-prediction-text.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/get-prediction-text-success.json'), true);

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            'https://api.replicate.com/v1/predictions/rrr4z55ocneqzikepnug6xezpe' => Http::response($completedResponse, 200),
        ]);

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->text)->toBe('Hello! How can I assist you today?');
    });

    it('handles model with version specified', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/create-prediction-text.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/get-prediction-text-success.json'), true);

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            'https://api.replicate.com/v1/predictions/rrr4z55ocneqzikepnug6xezpe' => Http::response($completedResponse, 200),
        ]);

        $response = Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct:v1')
            ->withPrompt('Hello!')
            ->generate();

        expect($response->text)->toBe('Hello! How can I assist you today?');
    });

    it('sends correct request payload', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/create-prediction-text.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/get-prediction-text-success.json'), true);

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            'https://api.replicate.com/v1/predictions/rrr4z55ocneqzikepnug6xezpe' => Http::response($completedResponse, 200),
        ]);

        Prism::text()
            ->using('replicate', 'meta/meta-llama-3.1-405b-instruct')
            ->withPrompt('Hello!')
            ->withMaxTokens(100)
            ->generate();

        Http::assertSent(function ($request): bool {
            $body = json_decode((string) $request->body(), true);

            return isset($body['input']['max_tokens'])
                && $body['input']['max_tokens'] === 100
                && isset($body['version'])
                && str_contains((string) $body['version'], 'meta-llama-3.1-405b-instruct');
        });
    });
});
