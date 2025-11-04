<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Replicate\Handlers\Stream;
use Prism\Prism\Text\Request;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('throws exception when tools used with streaming', function (): void {
    $tools = [
        (new Tool)->as('test')
            ->for('Test')
            ->using(fn (): string => 'result'),
    ];

    $request = new Request(
        model: 'meta/meta-llama-3.1-8b-instruct',
        providerKey: 'replicate',
        systemPrompts: [],
        prompt: 'Test',
        messages: [new UserMessage('Test')],
        maxSteps: 10,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: $tools,
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
    );

    $client = Http::fake([
        '*' => Http::response([]),
    ])->baseUrl('https://api.replicate.com/v1');

    $handler = new Stream($client);

    expect(fn (): array => iterator_to_array($handler->handle($request)))
        ->toThrow(PrismException::class, 'not supported with streaming');
});
