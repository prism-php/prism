<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('sends correct basic text generation payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([
            new UserMessage('Hello, how are you?'),
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKeys(['model', 'messages']);
        expect($payload['model'])->toBe('claude-3-5-haiku-latest');
        expect($payload['messages'])->toBe([
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hello, how are you?',
                    ],
                ],
            ],
        ]);

        return true;
    });
});

it('sends correct system messages in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withMessages([
            new UserMessage('Hello'),
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('system');
        expect($payload['system'])->toBe([
            [
                'type' => 'text',
                'text' => 'You are a helpful assistant.',
            ],
        ]);

        return true;
    });
});

it('sends correct temperature and top_p in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->usingTemperature(0.7)
        ->usingTopP(0.9)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('temperature');
        expect($payload)->toHaveKey('top_p');
        expect($payload['temperature'])->toBe(0.7);
        expect($payload['top_p'])->toBe(0.9);

        return true;
    });
});

it('sends correct max_tokens in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->withMaxTokens(1000)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload['max_tokens'])->toBe(1000);

        return true;
    });
});

it('sends correct tools in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $tool = Tool::as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'The city name', true);

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('What is the weather?')])
        ->withTools([$tool])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('tools');
        expect($payload['tools'])->toBe([
            [
                'name' => 'get_weather',
                'description' => 'Get current weather',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'description' => 'The city name',
                            'type' => 'string',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ]);

        return true;
    });
});

it('sends correct tool_choice in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    $tool = Tool::as('get_weather')
        ->for('Get current weather')
        ->withStringParameter('location', 'The city name', true);

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('What is the weather?')])
        ->withTools([$tool])
        ->withToolChoice(ToolChoice::Any)
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('tool_choice');
        expect($payload['tool_choice'])->toBe(['type' => 'any']);

        return true;
    });
});

it('sends correct thinking mode in payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Solve this math problem: 2+2')])
        ->withProviderOptions([
            'thinking' => [
                'enabled' => true,
                'budgetTokens' => 2048,
            ],
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('thinking');
        expect($payload['thinking'])->toBe([
            'type' => 'enabled',
            'budget_tokens' => 2048,
        ]);

        return true;
    });
});

it('sends correct thinking mode with default budget tokens', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Think about this')])
        ->withProviderOptions(['thinking' => ['enabled' => true]])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('thinking');
        expect($payload['thinking'])->toBe([
            'type' => 'enabled',
            'budget_tokens' => 1024, // default from config
        ]);

        return true;
    });
});

it('omits null values from payload', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->not->toHaveKey('system');
        expect($payload)->not->toHaveKey('tools');
        expect($payload)->not->toHaveKey('tool_choice');
        expect($payload)->not->toHaveKey('thinking');
        expect($payload)->not->toHaveKey('temperature');
        expect($payload)->not->toHaveKey('top_p');
        expect($payload)->not->toHaveKey('mcp_servers');

        return true;
    });
});

it('always includes max_tokens in payload because it is required by anthropic', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Test')])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        // Anthropic API requires max_tokens - it should always be present
        expect($payload)->toHaveKey('max_tokens');
        expect($payload['max_tokens'])->toBeInt();
        expect($payload['max_tokens'])->toBeGreaterThan(0);

        return true;
    });
});

it('can send images from file', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-image');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([
            new UserMessage(
                'What is this image',
                additionalContent: [
                    Image::fromLocalPath('tests/Fixtures/diamond.png'),
                ],
            ),
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $message = $request->data()['messages'][0]['content'];

        expect($message[0])->toBe([
            'type' => 'text',
            'text' => 'What is this image',
        ]);

        expect($message[1]['type'])->toBe('image');
        expect($message[1]['source']['data'])->toContain(
            base64_encode(file_get_contents('tests/Fixtures/diamond.png'))
        );
        expect($message[1]['source']['media_type'])->toBe('image/png');

        return true;
    });
});

it('sends correct mcp_servers', function (): void {
    FixtureResponse::fakeResponseSequence('v1/messages', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-haiku-latest')
        ->withMessages([new UserMessage('Think about this')])
        ->withProviderOptions([
            'mcp_servers' => [
                [
                    'name' => 'external-mcp',
                    'type' => 'url',
                    'url' => 'https://mcp-server.co/mcp',
                ],
            ],
        ])
        ->asText();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        expect($payload)->toHaveKey('mcp_servers');
        expect($payload['mcp_servers'])->toBe([
            [
                'name' => 'external-mcp',
                'type' => 'url',
                'url' => 'https://mcp-server.co/mcp',
            ],
        ]);

        return true;
    });
});
