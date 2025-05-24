<?php

declare(strict_types=1);

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\ValueObjects\MCPServer;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('includes MCP servers in structured request', function (): void {
    $mcpServer = new MCPServer('analytics-server', 'https://analytics.com', 'analytics-token');
    $schema = new ObjectSchema(
        'result',
        'The result object',
        [new StringSchema('data', 'The result data')]
    );

    $request = new StructuredRequest(
        systemPrompts: [],
        model: 'claude-3-sonnet',
        prompt: 'Analyze data',
        messages: [new UserMessage('Analyze this data')],
        maxTokens: 1000,
        temperature: 0.5,
        topP: 0.8,
        mcpServers: [$mcpServer],
        clientOptions: [],
        clientRetry: [],
        schema: $schema,
        mode: StructuredMode::Auto,
        providerOptions: []
    );

    expect($request->mcpServers())->toHaveCount(1);
    expect($request->mcpServers()[0])->toBe($mcpServer);
    expect($request->mcpServers()[0]->name)->toBe('analytics-server');
    expect($request->mcpServers()[0]->authorizationToken)->toBe('analytics-token');
});

it('handles empty MCP servers in structured request', function (): void {
    $schema = new ObjectSchema(
        'data',
        'The data object',
        [new StringSchema('items', 'The data items')]
    );

    $request = new StructuredRequest(
        systemPrompts: [],
        model: 'claude-3-sonnet',
        prompt: 'Process data',
        messages: [],
        maxTokens: 500,
        temperature: null,
        topP: null,
        mcpServers: [],
        clientOptions: [],
        clientRetry: [],
        schema: $schema,
        mode: StructuredMode::Auto,
        providerOptions: []
    );

    expect($request->mcpServers())->toBe([]);
});

it('preserves multiple MCP servers in structured request', function (): void {
    $server1 = new MCPServer('database', 'https://db.com', 'db-token');
    $server2 = new MCPServer('filesystem', 'https://fs.com');
    $server3 = new MCPServer('api', 'https://api.com', 'api-token', ['readonly' => true]);

    $schema = new ObjectSchema(
        'summary',
        'The summary object',
        [new StringSchema('text', 'The summary text')]
    );

    $request = new StructuredRequest(
        systemPrompts: [],
        model: 'claude-3-sonnet',
        prompt: 'Create summary',
        messages: [],
        maxTokens: 2000,
        temperature: 0.3,
        topP: 0.9,
        mcpServers: [$server1, $server2, $server3],
        clientOptions: [],
        clientRetry: [],
        schema: $schema,
        mode: StructuredMode::Auto,
        providerOptions: []
    );

    $mcpServers = $request->mcpServers();
    expect($mcpServers)->toHaveCount(3);
    expect($mcpServers[0])->toBe($server1);
    expect($mcpServers[1])->toBe($server2);
    expect($mcpServers[2])->toBe($server3);
    expect($mcpServers[2]->toolConfiguration)->toBe(['readonly' => true]);
});
