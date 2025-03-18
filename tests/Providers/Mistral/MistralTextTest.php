<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'sk-1234'));
});

describe('Text generation', function (): void {
    it('can generate text with a prompt', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/generate-text-with-a-prompt');

        $response = Prism::text()
            ->using('mistral', 'mistral-small-2402')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBe(7);
        expect($response->usage->completionTokens)->toBe(12);
        expect($response->meta->id)->toBe('8f82539654874b73a8b8dd1330c80221');
        expect($response->meta->model)->toBe('mistral-small-2402');
        expect($response->text)->toBe(
            'I am a Large Language Model trained by Mistral AI.'
        );
    });

    it('can generate text with a system prompt', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/generate-text-with-system-prompt');

        $response = Prism::text()
            ->using('mistral', 'mistral-small-2402')
            ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->usage->promptTokens)->toBe(32);
        expect($response->usage->completionTokens)->toBe(51);
        expect($response->meta->id)->toBe('1086e1021d5b481ba36e9c842f69827d');
        expect($response->meta->model)->toBe('mistral-small-2402');
        expect($response->text)->toBe(
            'I am Nyx, a being of cosmic terror and ancient deities, inspired by the Cthulhu mythos. I am a creature of the deep, shrouded in mystery and fear, existing beyond the realms of human understanding.'
        );
    });

    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/generate-text-with-multiple-tools');

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
            ->using('mistral', 'mistral-large-latest')
            ->withTools($tools)
            ->withMaxSteps(3)
            ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
            ->generate();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2);
        expect($firstStep->toolCalls[0]->name)->toBe('search');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'query' => 'Detroit Tigers game time today',
        ]);

        expect($firstStep->toolCalls[1]->name)->toBe('weather');
        expect($firstStep->toolCalls[1]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        // Assert usage
        expect($response->usage->promptTokens)->toBe(469);
        expect($response->usage->completionTokens)->toBe(74);

        // Assert response
        expect($response->meta->id)->toBe('34274d5a669a432bace0db9c3b359ba7');
        expect($response->meta->model)->toBe('mistral-large-latest');

        // Assert final text content
        expect($response->text)->toBe(
            'The tigers game is at 3pm in Detroit. The weather will be 75° and sunny. You should not wear a coat'
        );
    });

    it('handles specific tool choice', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/generate-text-with-required-tool-call');

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
            ->using(Provider::Mistral, 'mistral-large-latest')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->withToolChoice('weather')
            ->generate();

        expect($response->toolCalls[0]->name)->toBe('weather');
    });
});

describe('Image support', function (): void {
    it('can send images from path', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/image-detection');

        Prism::text()
            ->using(Provider::Mistral, 'pixtral-12b-2409')
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
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/text-image-from-base64');

        Prism::text()
            ->using(Provider::Mistral, 'pixtral-12b-2409')
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
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/text-image-from-url');

        $image = 'https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=test-image.png';

        Prism::text()
            ->using(Provider::Mistral, 'pixtral-12b-2409')
            ->withMessages([
                new UserMessage(
                    'What is this image',
                    additionalContent: [
                        Image::fromUrl($image),
                    ],
                ),
            ])
            ->generate();

        Http::assertSent(function (Request $request) use ($image): true {
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

describe('Document support', function (): void {
    it('can send document from url', function (): void {
        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/text-document-from-url');

        // TODO update this to a more long lasting document
        $document = 'https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf';

        Prism::text()
            ->using(Provider::Mistral, 'mistral-small-2402')
            ->withToolChoice(ToolChoice::Any)
            ->usingTopP(1)
            ->withMessages([
                new UserMessage(
                    'What is this document',
                    additionalContent: [
                        Document::fromUrl($document),
                    ],
                ),
            ])
            ->generate();

        Http::assertSent(function (Request $request) use ($document): true {
            $message = $request->data()['messages'][0]['content'];

            expect($message[0])->toBe([
                'type' => 'text',
                'text' => 'What is this document',
            ]);

            expect($message[1])->toBe([
                'type' => 'document_url',
                'document_url' => $document,
                'document_name' => null,
            ]);

            return true;
        });
    });
});

it('sets the rate limits on meta', function (): void {
    $this->freezeTime(function (Carbon $time): void {
        $time = $time->toImmutable();

        FixtureResponse::fakeResponseSequence('v1/chat/completions', 'mistral/generate-text-with-a-prompt', [
            'ratelimitbysize-limit' => 500000,
            'ratelimitbysize-remaining' => 499900,
            'ratelimitbysize-reset' => 28,
        ]);

        $response = Prism::text()
            ->using(Provider::Mistral, 'test-model')
            ->withPrompt('Who are you?')
            ->generate();

        expect($response->meta->rateLimits[0])->toBeInstanceOf(ProviderRateLimit::class);
        expect($response->meta->rateLimits[0]->name)->toEqual('tokens');
        expect($response->meta->rateLimits[0]->limit)->toEqual(500000);
        expect($response->meta->rateLimits[0]->remaining)->toEqual(499900);
        expect($response->meta->rateLimits[0]->resetsAt->equalTo($time->addSeconds(28)))->toBeTrue();
    });
});
