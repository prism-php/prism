<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderTool;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

it('can generate text with a prompt', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);
    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(0);
    expect($response->meta->id)->toContain('resp_');
    expect($response->meta->model)->toContain('gpt-4o');
    expect($response->text)->toBeString();
});

it('can generate text with a system prompt', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-system-prompt'
    );

    $response = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withSystemPrompt('MODEL ADOPTS ROLE of [PERSONA: Nyx the Cthulhu]!')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->usage->promptTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(20);
    expect($response->usage->completionTokens)
        ->toBeNumeric()
        ->toBeGreaterThan(20);
    expect($response->meta->id)->toContain('resp_');
    expect($response->meta->model)->toContain('gpt-4o');
    expect($response->text)
        ->toBeString()
        ->toContain('Nyx');
});

it('sends the organization header when set', function (): void {
    config()->set('prism.providers.openai.organization', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Organization')[0] === 'echolabs');
});

it('does not send the organization header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.organization');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Organization')));
});

it('sends the api key header when set', function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-1234');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer sk-1234');
});

it('does not send the api key header', function (): void {
    config()->offsetUnset('prism.providers.openai.api_key');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();
    Http::assertSent(fn (Request $request): bool => empty($request->header('Authorization')));
});

it('sends the project header when set', function (): void {
    config()->set('prism.providers.openai.project', 'echolabs');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('OpenAI-Project')[0] === 'echolabs');
});

it('does not send the project header if one is not given', function (): void {
    config()->offsetUnset('prism.providers.openai.project');

    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-a-prompt');

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt('Who are you?')
        ->asText();

    Http::assertSent(fn (Request $request): bool => empty($request->header('OpenAI-Project')));
});

describe('tools', function (): void {
    it('can generate text using multiple tools and multiple steps', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/responses',
            'openai/generate-text-with-multiple-tools',
        );

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is today at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('openai', 'gpt-4o')
            ->withTools($tools)
            ->usingTemperature(0)
            ->withMaxSteps(3)
            ->withSystemPrompt('Current Date: '.now()->toDateString())
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asText();

        // Assert tool calls in the first step
        $firstStep = $response->steps[0];
        expect($firstStep->toolCalls)->toHaveCount(2);
        expect($firstStep->toolCalls[0]->name)->toBe('search');
        expect($firstStep->toolCalls[0]->arguments())->toBe([
            'query' => 'Detroit Tigers game March 14 2025 time',
        ]);

        expect($firstStep->toolCalls[1]->name)->toBe('weather');
        expect($firstStep->toolCalls[1]->arguments())->toBe([
            'city' => 'Detroit',
        ]);

        expect($response->usage->promptTokens)->toBeNumeric();
        expect($response->usage->completionTokens)->toBeNumeric();

        // Assert response
        expect($response->meta->id)->toContain('resp_');
        expect($response->meta->model)->toContain('gpt-4o');

        // Assert final text content
        expect($response->text)->toBe(
            "The Detroit Tigers game is today at 3 PM in Detroit. The weather in Detroit will be 75°F and sunny, so you won't need a coat!"
        );
    });

    it('handles specific tool choice', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-required-tool-call');

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
            ->using('openai', 'gpt-4o')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->withToolChoice('weather')
            ->asText();

        expect($response->toolCalls[0]->name)->toBe('weather');
    });

    it('handles tool choice when null', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-null-tool-call');

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

        Prism::text()
            ->using('openai', 'gpt-4o')
            ->withPrompt('Do something')
            ->withTools($tools)
            ->asText();
    })->throwsNoExceptions();

    it('it handles a provider tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-code-interpreter');

        $response = Prism::text()
            ->using('openai', 'gpt-4.1')
            ->withPrompt('Solve the equation 3x + 10 = 14.')
            ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
            ->asText();

        expect($response->text)->toContain('frac{4}{3}');
    });

    it('handles a provider tool with a user defined tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-required-tool-call-and-provider-tool');

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
            ->using('openai', 'gpt-4.1')
            ->withPrompt('If the current temperature in Detroit is X, what is Y in the following equation: 3x + 10 = Y?')
            ->withTools($tools)
            ->withProviderTools([new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])])
            ->withMaxSteps(3)
            ->asText();

        expect($response->text)->toContain('235');
    });

    it('passes parallel tool calls setting', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/responses',
            'openai/generate-text-with-multiple-tools',
        );

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => "The weather in {$city} will be 75° and sunny"),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is today at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('openai', 'gpt-4o')
            ->withTools($tools)
            ->usingTemperature(0)
            ->withMaxSteps(3)
            ->withProviderOptions(['parallel_tool_calls' => false])
            ->withSystemPrompt('Current Date: '.now()->toDateString())
            ->withPrompt('What time is the tigers game today and should I wear a coat?')
            ->asText();

        expect($response->text)->toBe(
            "The Detroit Tigers game is today at 3 PM in Detroit. The weather in Detroit will be 75°F and sunny, so you won't need a coat!"
        );

        Http::assertSent(fn (Request $request): bool => $request->data()['parallel_tool_calls'] === false);
    });
});

it('sets usage correctly with automatic caching', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/cache-usage-automatic-caching'
    );

    $prompt = fake()->paragraphs(40, true);

    Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->asText();

    $two = Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->asText();

    expect($two->usage)
        ->promptTokens->toEqual(1111 - 1024)
        ->completionTokens->toEqual(109)
        ->cacheWriteInputTokens->toEqual(null)
        ->cacheReadInputTokens->toEqual(1024);
});

it('uses meta to provide previous response id', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What have we talked about?')
        ->withProviderOptions([
            'previous_response_id' => 'resp_foo',
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'previous_response_id'))->toBe('resp_foo');

        return true;
    });
});

it('uses meta to set auto truncation', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What have we talked about?')
        ->withProviderOptions([
            'truncation' => 'auto',
        ])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        expect(data_get($body, 'truncation'))->toBe('auto');

        return true;
    });
});

it('can analyze images with detail parameter', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $image = \Prism\Prism\ValueObjects\Media\Image::fromLocalPath('tests/Fixtures/diamond.png')
        ->withProviderOptions(['detail' => 'high']);

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What do you see in this image?', [$image])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        $imageContent = $body['input'][0]['content'][1];

        expect($imageContent['type'])->toBe('input_image');
        expect($imageContent['detail'])->toBe('high');
        expect($imageContent['image_url'])->toStartWith('data:image/png;base64,');

        return true;
    });
});

it('omits detail parameter when not specified', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/generate-text-with-a-prompt'
    );

    $image = \Prism\Prism\ValueObjects\Media\Image::fromLocalPath('tests/Fixtures/diamond.png');

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('What do you see in this image?', [$image])
        ->asText();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        $imageContent = $body['input'][0]['content'][1];

        expect($imageContent['type'])->toBe('input_image');
        expect($imageContent)->not->toHaveKey('detail');
        expect($imageContent['image_url'])->toStartWith('data:image/png;base64,');

        return true;
    });
});

it('can analyze documents', function (): void {
    FixtureResponse::fakeResponseSequence(
        'v1/responses',
        'openai/text-response-with-document'
    );

    $document = Document::fromLocalPath('tests/Fixtures/test-pdf.pdf');

    $response = Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Summarize this document', [$document])
        ->asText();

    expect($response->text)->not->toBeEmpty();

    Http::assertSent(function (Request $request): true {
        $body = json_decode($request->body(), true);

        $documentContent = $body['input'][0]['content'][1];

        expect($documentContent['type'])->toBe('input_file');
        expect($documentContent['filename'])->not->toBeEmpty();

        return true;
    });
});

it('sends reasoning effort when defined', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/text-reasoning-effort');

    Prism::text()
        ->using('openai', 'gpt-5')
        ->withPrompt('Who are you?')
        ->withProviderOptions([
            'reasoning' => [
                'effort' => 'low',
            ],
        ])
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->data()['reasoning']['effort'] === 'low');
});

describe('citations', function (): void {
    it('adds citations to additionalContent on response steps and assistant message for the web search tool', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today? Please provide citations.')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        $citationChunk = Arr::first(
            $response->additionalContent['citations'],
            fn (MessagePartWithCitations $part): bool => $part->citations !== [] && $part->citations[0]->sourceType === CitationSourceType::Url
        );

        expect($citationChunk->outputText)->toContain('temperatures');
        expect($citationChunk->citations)->toHaveCount(1);
        expect($citationChunk->citations[0]->sourceType)->toBe(CitationSourceType::Url);
        expect($citationChunk->citations[0]->sourceTitle)->toContain('weather');
        expect($citationChunk->citations[0]->source)->toBe('https://www.metcheck.com/WEATHER/dayforecast.asp?dateFor=07%2F06%2F2025&lat=51.508500&location=London&locationID=2364784&lon=-0.125700&utm_source=openai');

        expect($response->steps[0]->additionalContent['citations'])->toHaveCount(1);
        expect($response->steps[0]->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);

        expect($response->messages->last()->additionalContent['citations'])->toHaveCount(1);
        expect($response->messages->last()->additionalContent['citations'][0])->toBeInstanceOf(MessagePartWithCitations::class);
    });

    it('can handle citations on on a previous assistant message with a document', function (): void {
        FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-web-search-citations-included-in-turn');

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withPrompt('What is the weather going to be like in London today? Please provide citations.')
            ->withProviderTools([new ProviderTool(type: 'web_search_preview', name: 'web_search_preview')])
            ->asText();

        $responseTwo = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4.1-2025-04-14')
            ->withMessages([
                ...$response->steps->last()->messages,
                new UserMessage('Is the source you have cited reliable?'),
            ])
            ->asText();

        expect($responseTwo->text)->toContain('Metcheck');
    });
});
