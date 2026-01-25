<?php

declare(strict_types=1);

use Prism\Prism\Telemetry\Semantics\OpenInferenceMapper;

it('maps text generation span to LLM kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'provider' => 'openai',
    ]);

    expect($attrs['openinference.span.kind'])->toBe('LLM');
    expect($attrs['llm.model_name'])->toBe('gpt-4');
    expect($attrs['llm.provider'])->toBe('openai');
});

it('maps streaming span to LLM kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('streaming', [
        'model' => 'gpt-4-turbo',
    ]);

    expect($attrs['openinference.span.kind'])->toBe('LLM');
    expect($attrs['llm.model_name'])->toBe('gpt-4-turbo');
});

it('maps tool_call span to TOOL kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('tool_call', [
        'tool' => [
            'name' => 'search',
            'call_id' => 'call_123',
            'arguments' => ['query' => 'test'],
            'description' => 'Search the web',
            'parameters' => ['type' => 'object'],
        ],
        'output' => 'Search results',
    ]);

    expect($attrs['openinference.span.kind'])->toBe('TOOL');
    expect($attrs['tool.name'])->toBe('search');
    expect($attrs['tool.call.id'])->toBe('call_123');
    expect($attrs['tool.output'])->toBe('Search results');
    expect($attrs['tool.description'])->toBe('Search the web');
});

it('maps embedding_generation span to EMBEDDING kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('embedding_generation', [
        'model' => 'text-embedding-ada-002',
        'inputs' => ['Hello world', 'Test input'],
        'usage' => ['tokens' => 10],
    ]);

    expect($attrs['openinference.span.kind'])->toBe('EMBEDDING');
    expect($attrs['embedding.model_name'])->toBe('text-embedding-ada-002');
    expect($attrs['embedding.embeddings.0.embedding.text'])->toBe('Hello world');
    expect($attrs['embedding.embeddings.1.embedding.text'])->toBe('Test input');
});

it('maps structured_output span to CHAIN kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('structured_output', [
        'model' => 'gpt-4',
        'schema' => [
            'name' => 'UserProfile',
            'definition' => ['type' => 'object'],
        ],
    ]);

    expect($attrs['openinference.span.kind'])->toBe('CHAIN');
    expect($attrs['output.schema.name'])->toBe('UserProfile');
});

it('maps http_call span correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('http_call', [
        'http' => [
            'method' => 'POST',
            'url' => 'https://api.openai.com/v1/chat/completions',
            'status_code' => 200,
        ],
    ]);

    expect($attrs['openinference.span.kind'])->toBe('CHAIN');
    expect($attrs['http.method'])->toBe('POST');
    expect($attrs['http.url'])->toBe('https://api.openai.com/v1/chat/completions');
    expect($attrs['http.status_code'])->toBe(200);
});

it('converts token usage correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ],
    ]);

    expect($attrs['llm.token_count.prompt'])->toBe(100);
    expect($attrs['llm.token_count.completion'])->toBe(50);
    expect($attrs['llm.token_count.total'])->toBe(150);
});

it('converts invocation parameters to JSON', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'temperature' => 0.7,
        'max_tokens' => 100,
        'top_p' => 0.9,
    ]);

    expect($attrs)->toHaveKey('llm.invocation_parameters');

    $params = json_decode((string) $attrs['llm.invocation_parameters'], true);
    expect($params['temperature'])->toBe(0.7);
    expect($params['max_tokens'])->toBe(100);
    expect($params['top_p'])->toBe(0.9);
});

it('extracts system prompt from messages', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ],
    ]);

    expect($attrs['llm.system'])->toBe('You are a helpful assistant.');
});

it('formats input messages correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ],
    ]);

    expect($attrs['llm.input_messages.0.message.role'])->toBe('user');
    expect($attrs['llm.input_messages.0.message.content'])->toBe('Hello');
    expect($attrs['llm.input_messages.1.message.role'])->toBe('assistant');
    expect($attrs['llm.input_messages.1.message.content'])->toBe('Hi there!');
});

it('formats output messages correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'output' => 'Hello there!',
        'tool_calls' => [
            ['id' => 'call_1', 'name' => 'search', 'arguments' => ['q' => 'test']],
        ],
    ]);

    expect($attrs['llm.output_messages.0.message.role'])->toBe('assistant');
    expect($attrs['llm.output_messages.0.message.content'])->toBe('Hello there!');
    expect($attrs['llm.output_messages.0.message.tool_calls.0.tool_call.id'])->toBe('call_1');
});

it('maps user and session attributes to OpenInference format', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'metadata' => [
            'user_id' => 'user_123',
            'session_id' => 'session_456',
        ],
    ]);

    // OpenInference uses user.id and session.id for user tracking
    expect($attrs['user.id'])->toBe('user_123');
    expect($attrs['session.id'])->toBe('session_456');
});

it('maps agent to agent.name per OpenInference spec', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'metadata' => [
            'agent' => 'support-bot',
        ],
    ]);

    // Agent is a reserved OpenInference attribute
    // @see https://arize-ai.github.io/openinference/spec/semantic_conventions.html
    expect($attrs['agent.name'])->toBe('support-bot');
    expect($attrs)->not->toHaveKey('tag.agent'); // Not a spec attribute
});

it('formats general metadata with prefix', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'metadata' => [
            'custom_key' => 'custom_value',
            'user_email' => 'test@example.com',
        ],
    ]);

    // General metadata goes under metadata.* prefix
    expect($attrs['metadata.custom_key'])->toBe('custom_value');
    expect($attrs['metadata.user_email'])->toBe('test@example.com');
});

it('maps tags to OpenInference tag.tags list format', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'metadata' => [
            'tags' => [
                'environment' => 'production',
                'app' => 'my-app',
            ],
        ],
    ]);

    // Per OpenInference spec, tag.tags is a list of strings
    // Key-value pairs are converted to "key:value" format
    // @see https://arize-ai.github.io/openinference/spec/semantic_conventions.html
    expect($attrs['tag.tags'])->toBe('["environment:production","app:my-app"]');
});

it('supports simple string tags in tag.tags list', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        'metadata' => [
            'tags' => ['shopping', 'travel'],
        ],
    ]);

    // Simple string tags (integer keys) are passed through
    expect($attrs['tag.tags'])->toBe('["shopping","travel"]');
});

it('maps exception events correctly', function (): void {
    $mapper = new OpenInferenceMapper;

    $events = [
        [
            'name' => 'exception',
            'timeNanos' => 1000000000,
            'attributes' => [
                'type' => 'RuntimeException',
                'message' => 'Test error',
            ],
        ],
    ];

    $mappedEvents = $mapper->mapEvents($events);

    expect($mappedEvents[0]['attributes']['exception.type'])->toBe('RuntimeException');
    expect($mappedEvents[0]['attributes']['exception.message'])->toBe('Test error');
    expect($mappedEvents[0]['attributes']['exception.escaped'])->toBeTrue();
});

it('filters null values from attributes', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('text_generation', [
        'model' => 'gpt-4',
        // No usage provided - should not appear in output
    ]);

    expect($attrs)->not->toHaveKey('llm.token_count.prompt');
    expect($attrs)->not->toHaveKey('llm.token_count.completion');
    expect($attrs)->not->toHaveKey('llm.token_count.total');
});

it('maps unknown operation to CHAIN kind', function (): void {
    $mapper = new OpenInferenceMapper;

    $attrs = $mapper->map('unknown_operation', [
        'model' => 'gpt-4',
    ]);

    expect($attrs['openinference.span.kind'])->toBe('CHAIN');
});
