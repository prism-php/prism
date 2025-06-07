<?php

declare(strict_types=1);

namespace Tests\Providers\FireworksAI;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.fireworksai.api_key', env('FIREWORKS_API_KEY', 'fw-1234'));
    config()->set('prism.providers.fireworksai.url', 'https://api.fireworks.ai/inference/v1');
});

describe('Streaming for FireworksAI', function (): void {
    it('can stream text responses', function (): void {
        $streamData = [
            'data: {"id":"chatcmpl-fw-stream-1","object":"chat.completion.chunk","created":1234567890,"model":"accounts/fireworks/models/llama-v3p3-70b-instruct","choices":[{"index":0,"delta":{"role":"assistant","content":"Hello"},"finish_reason":null}]}',
            '',
            'data: {"id":"chatcmpl-fw-stream-1","object":"chat.completion.chunk","created":1234567890,"model":"accounts/fireworks/models/llama-v3p3-70b-instruct","choices":[{"index":0,"delta":{"content":" world"},"finish_reason":null}]}',
            '',
            'data: {"id":"chatcmpl-fw-stream-1","object":"chat.completion.chunk","created":1234567890,"model":"accounts/fireworks/models/llama-v3p3-70b-instruct","choices":[{"index":0,"delta":{"content":"!"},"finish_reason":null}]}',
            '',
            'data: {"id":"chatcmpl-fw-stream-1","object":"chat.completion.chunk","created":1234567890,"model":"accounts/fireworks/models/llama-v3p3-70b-instruct","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":3,"total_tokens":13}}',
            '',
            'data: [DONE]',
            '',
        ];

        Http::fake([
            'https://api.fireworks.ai/inference/v1/chat/completions' => Http::response(
                implode("\n", $streamData),
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $stream = Prism::text()
            ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3p3-70b-instruct')
            ->withPrompt('Say hello')
            ->asStream();

        $chunks = [];
        $lastChunk = null;

        foreach ($stream as $chunk) {
            if ($chunk->text !== '') {
                $chunks[] = $chunk->text;
            }
            $lastChunk = $chunk;
        }

        expect($chunks)->toBe(['Hello', ' world', '!'])
            ->and($lastChunk->finishReason->name)->toBe('Stop');

        // Note: Usage stats in streaming are captured but not exposed in chunks directly
        // This is a limitation in the current Prism architecture
    });
});
