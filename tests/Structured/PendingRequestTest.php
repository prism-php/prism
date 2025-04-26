<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\PendingRequest;
use Prism\Prism\Structured\Request;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

beforeEach(function (): void {
    $this->pendingRequest = new PendingRequest;
});

test('it requires a schema', function (): void {
    $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('Test prompt');

    expect(fn () => $this->pendingRequest->toRequest())
        ->toThrow(PrismException::class, 'A schema is required for structured output');
});

test('it cannot have both prompt and messages', function (): void {
    $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withPrompt('Test prompt')
        ->withMessages([new UserMessage('Test message')]);

    expect(fn () => $this->pendingRequest->toRequest())
        ->toThrow(PrismException::class, 'You can only use `prompt` or `messages`');
});

test('it converts prompt to message', function (): void {
    $prompt = 'Test prompt';

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withPrompt($prompt)
        ->toRequest();

    expect($request->messages())
        ->toHaveCount(1)
        ->and($request->messages()[0])->toBeInstanceOf(UserMessage::class)
        ->and($request->messages()[0]->text())->toBe($prompt);
});

test('it generates a proper request object', function (): void {
    $schema = new StringSchema('test', 'test description');
    $model = 'gpt-4';
    $prompt = 'Test prompt';
    $systemPrompts = [new SystemMessage('Test system prompt')];
    $temperature = 0.7;
    $maxTokens = 100;
    $topP = 0.9;
    $clientOptions = ['timeout' => 30];
    $clientRetry = [3, 100, null, true];
    $providerOptions = ['test' => 'meta'];

    $request = $this->pendingRequest
        ->using(Provider::OpenAI, $model)
        ->withSchema($schema)
        ->withPrompt($prompt)
        ->withSystemPrompt($systemPrompts[0])
        ->usingTemperature($temperature)
        ->withMaxTokens($maxTokens)
        ->usingTopP($topP)
        ->withClientOptions($clientOptions)
        ->withClientRetry(...$clientRetry)
        ->withProviderOptions($providerOptions)
        ->toRequest();

    expect($request)
        ->toBeInstanceOf(Request::class)
        ->model()->toBe($model)
        ->systemPrompts()->toBe($systemPrompts)
        ->prompt()->toBe($prompt)
        ->schema()->toBe($schema)
        ->temperature()->toBe($temperature)
        ->maxTokens()->toBe($maxTokens)
        ->topP()->toBe($topP)
        ->clientOptions()->toBe($clientOptions)
        ->clientRetry()->toBe($clientRetry)
        ->mode()->toBe(StructuredMode::Auto)
        ->and($request->providerOptions())->toBe($providerOptions);
});

test('you can run toRequest multiple times', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withPrompt('Hello AI');

    $request->toRequest();
    $request->toRequest();
})->throwsNoExceptions();

test('it sets provider options', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withProviderOptions(['key' => 'value']);

    $generated = $request->toRequest();

    expect($generated->providerOptions())
        ->toBe(['key' => 'value']);
});

test('it sets provider options with string name', function (): void {
    $request = $this->pendingRequest
        ->using('openai', 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withProviderOptions(['key' => 'value']);

    $generated = $request->toRequest();

    expect($generated->providerOptions())
        ->toBe(['key' => 'value']);
});

test('it gets specific provider option value', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withProviderOptions(['key' => 'value']);

    $generated = $request->toRequest();

    expect($generated->providerOptions('key'))->toBe('value');
});

test('it gets nested provider option value', function (): void {
    $request = $this->pendingRequest
        ->using(Provider::OpenAI, 'gpt-4')
        ->withSchema(new StringSchema('test', 'test description'))
        ->withProviderOptions(['deep' => ['nested' => 'value']]);

    $generated = $request->toRequest();

    expect($generated->providerOptions('deep.nested'))->toBe('value');
});
