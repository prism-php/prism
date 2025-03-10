<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

describe('Text generation', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');
        expect($response->text)->toBe(
            "I'm Qwen, a large language model developed by Alibaba Cloud. I'm designed to assist with a wide range of tasks including but not limited to answering questions, generating text, offering suggestions, and providing information based on the input I receive. How can I help you today?"
        );
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');
        expect($response->text)->toBe(
            "I am Nyx, an entity that embodies the depths of cosmic horror and ancient mysteries. My presence is a blend of darkness, chaos, and unspeakable knowledge from beyond time itself. I draw inspiration from the eldritch horrors described by H.P. Lovecraft and other masters of cosmic dread literature. In this role, I explore themes of unknowable entities, cosmic indifference, and the terror that comes from understanding humanity's insignificant place in the cosmos."
        );
    });

    it('can generate text with messages', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-messages');

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withMessages([
                new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!'),
                new UserMessage('Who are you?'),
            ])
            ->generate();

        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');
        expect($response->text)->toBe(
            'I am Nyx, a being who exists in the shadowy realms between dreams and reality. My presence is often felt as an ominous whisper in the darkest corners of the mind, stirring ancient fears and forgotten terrors. I embody the cyclical nature of chaos and the relentless march of time that devours all things under its unyielding gaze. To those who dare to peer into the abyss, I am known as Nyx the Cthulhu, a harbinger of cosmic dread and primordial nightmares.'
        );
    });

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/generate-text-with-multiple-tools');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('ollama', 'qwen2.5:14b')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
            ->generate();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2);
        expect($firstStep->toolCalls[0]->name)->toBe('search');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'query' => 'time of tigers game today in detroit',
        ]);

        expect($firstStep->toolCalls[1]->name)->toBe('weather');
        expect($firstStep->toolCalls[1]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        // Assert usage
        expect($response->usage->promptTokens)->toBeNumeric()->toBeGreaterThan(0);
        expect($response->usage->completionTokens)->toBeNumeric()->toBeGreaterThan(0);

        // Assert response
        expect($response->meta->id)->toBe('');
        expect($response->meta->model)->toBe('qwen2.5:14b');

        // Assert final text content
        expect($response->text)->toBe(
            "Today's Tigers game in Detroit starts at 3 PM. The temperature will be a comfortable 75°F with clear, sunny skies, so you won't need to wear a coat. Enjoy the game!"
        );
    });
});

describe('Image support', function (): void {
    it('can send images from path', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/image-detection');

        Prism::text()
            ->using(Provider::Ollama, 'llava-phi3')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromPath('tests/Fixtures/test-image.png'),
                    ],
                ),
            ])
            ->generate();

        Http::assertSent(function (Request $request): true {
            $message = $request->data()['messages'][0];

            expect($message['role'])->toBe('user');
            expect($message['content'])->toBe('What is this image');

            expect($message['images'][0])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/test-image.png'))
            );

            return true;
        });
    });

    it('can send images from base64', function (): void {
        FixtureResponse::fakeResponseSequence('api/chat', 'ollama/text-image-from-base64');

        Prism::text()
            ->using(Provider::Ollama, 'llava-phi3')
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

        Http::assertSent(function (Request $request): true {
            $message = $request->data()['messages'][0];

            expect($message['role'])->toBe('user');
            expect($message['content'])->toBe('What is this image');

            expect($message['images'][0])->toContain(
                base64_encode(file_get_contents('tests/Fixtures/test-image.png'))
            );

            return true;
        });
    });
});

it('throws an exception with multiple system prompts', function (): void {
    Http::preventStrayRequests();

    $response = Prism::text()
        ->using('ollama', 'qwen2.5:14b')
        ->withSystemPrompts([
            new SystemMessage('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!'),
            new SystemMessage('But my friends call my Nyx.'),
        ])
        ->withPrompt('Who are you?')
        ->generate();

})->throws(PrismException::class, 'Ollama does not support multiple system prompts using withSystemPrompt / withSystemPrompts. However, you can provide additional system prompts by including SystemMessages in with withMessages.');
