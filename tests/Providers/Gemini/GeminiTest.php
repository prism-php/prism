<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use EchoLabs\Prism\Enums\FinishReason;
use EchoLabs\Prism\Enums\Provider;
use EchoLabs\Prism\Enums\ToolChoice;
use EchoLabs\Prism\Facades\Tool;
use EchoLabs\Prism\Prism;
use EchoLabs\Prism\ValueObjects\Messages\Support\Image;
use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'gm-1234567890'));
});

describe('Text generation for Gemini', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence(
            '*', 
            'gemini/generate-text-with-a-prompt'
        );

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withPrompt('Who are you?')
            ->withMaxTokens(10)
            ->generate();

        expect($response->text)->toBe(
            "I am a large language model, trained by Google.  I am an AI, and I don't have a name, feelings, or personal experiences.  My purpose is to process information and respond to a wide range of prompts and questions in a helpful and informative way.\n"
        )
            ->and($response->usage->promptTokens)->toBe(4)
            ->and($response->usage->completionTokens)->toBe(57)
            ->and($response->response)->toBe([
                'avgLogprobs' => -0.12800796408402293,
                'model' => 'gemini-1.5-flash'
            ])
            ->and($response->finishReason)->toBe(FinishReason::Stop);
    });

    it('can generate text with a system prompt', function (): void {
        // FixtureResponse::fakeResponseSequence(
        //     '*',
        //     'gemini/generate-text-with-system-prompt'
        // );

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-1.5-flash')
            ->withSystemPrompt('You are a helpful AI assistant named Prism generated by echolabs.')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->text)->toBe(
            'I am Prism, an AI assistant created by echolabs. I am here to help you with any questions or tasks you may have.'
        )
            ->and($response->usage->promptTokens)->toBe(12)
            ->and($response->usage->completionTokens)->toBe(27)
            ->and($response->response)->toBe([
                'avgLogprobs' => -0.12800796408402293,
                'model' => 'gemini-pro'
            ])
            ->and($response->finishReason)->toBe(FinishReason::Stop);
    })->only();

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'gemini/generate-text-with-multiple-tools');

        $tools = [
            Tool::as('get_weather')
                ->for('use this tool when you need to get weather for the city')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 45° and cold'),
            Tool::as('search_games')
                ->for('useful for searching current games times in the city')
                ->withStringParameter('city', 'The city that you want the game times for')
                ->using(fn (string $city): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-pro')
            ->withTools($tools)
            ->withMaxSteps(4)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat? please check all the details from tools')
            ->generate();

        expect($response->steps[0]->toolCalls)->toHaveCount(1);
        expect($response->steps[0]->toolCalls[0]->name)->toBe('search_games');
        expect($response->steps[0]->toolCalls[0]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        expect($response->steps[1]->toolCalls[0]->name)->toBe('get_weather');
        expect($response->steps[1]->toolCalls[0]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        expect($response->usage->promptTokens)->toBe(840);
        expect($response->usage->completionTokens)->toBe(60);
        expect($response->response['model'])->toBe('gemini-pro');
        expect($response->text)->toBe(
            'The Tigers game in Detroit today is at 3pm, and considering the weather will be 45° and cold, you should definitely wear a coat.'
        );
    });
});

describe('Image support with Gemini', function (): void {
    it('can send images from path', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'gemini/image-detection');

        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-pro-vision')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromPath('tests/Fixtures/test-image.png'),
                    ],
                ),
            ])
            ->generate();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this image',
            ]);

            expect($message[1]['image_url']['url'])->toStartWith('data:image/png;base64,');
            expect($message[1]['image_url']['url'])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/test-image.png'))
            );

            return true;
        });
    });

    it('can send images from base64', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'gemini/text-image-from-base64');

        Prism::text()
            ->using(Provider::Gemini, 'gemini-pro-vision')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromBase64(
                            base64_encode(file_get_contents('tests/Fixtures/test-image.png')),
                            'image/png'
                        ),
                    ],
                ),
            ])
            ->generate();

        Http::assertSent(function (Request $request): bool {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this image',
            ]);

            expect($message[1]['image_url']['url'])->toStartWith('data:image/png;base64,');
            expect($message[1]['image_url']['url'])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/test-image.png'))
            );

            return true;
        });
    });

    it('can send images from url', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'gemini/text-image-from-url');

        $image = 'https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=test-image.png';

        Prism::text()
            ->using(Provider::Gemini, 'gemini-pro-vision')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromUrl($image),
                    ],
                ),
            ])
            ->generate();

        Http::assertSent(function (Request $request) use ($image): bool {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this image',
            ]);

            expect($message[1]['image_url']['url'])->toBe($image);

            return true;
        });
    });
});

it('handles specific tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'gemini/generate-text-with-specific-tool-call');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-pro')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice('weather')
        ->generate();

    expect($response->toolCalls[0]->name)->toBe('weather');
});

it('handles required tool choice', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'gemini/generate-text-with-required-tool-call');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $response = Prism::text()
        ->using(Provider::Gemini, 'gemini-pro')
        ->withPrompt('Do something')
        ->withTools($tools)
        ->withToolChoice(ToolChoice::Any)
        ->generate();

    expect($response->toolCalls[0]->name)->toBeIn(['weather', 'search']);
});
