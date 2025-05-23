<?php

declare(strict_types=1);

use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\MCPServer;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('includes MCP servers in text request', function (): void {
    $mcpServer = new MCPServer('test-server', 'https://test.com', 'token');

    $request = new TextRequest(
        model: 'claude-3-sonnet',
        systemPrompts: [],
        prompt: 'Test prompt',
        messages: [new UserMessage('Test message')],
        maxSteps: 5,
        maxTokens: 1000,
        temperature: 0.7,
        topP: 0.9,
        tools: [],
        mcpServers: [$mcpServer],
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: []
    );

    expect($request->mcpServers())->toHaveCount(1);
    expect($request->mcpServers()[0])->toBe($mcpServer);
    expect($request->mcpServers()[0]->name)->toBe('test-server');
});

it('handles empty MCP servers array', function (): void {
    $request = new TextRequest(
        model: 'claude-3-sonnet',
        systemPrompts: [],
        prompt: 'Test prompt',
        messages: [],
        maxSteps: 5,
        maxTokens: 1000,
        temperature: 0.7,
        topP: 0.9,
        tools: [],
        mcpServers: [],
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: []
    );

    expect($request->mcpServers())->toBe([]);
});

it('preserves multiple MCP servers in correct order', function (): void {
    $server1 = new MCPServer('server1', 'https://server1.com');
    $server2 = new MCPServer('server2', 'https://server2.com', 'token2');
    $server3 = new MCPServer('server3', 'https://server3.com');

    $request = new TextRequest(
        model: 'claude-3-sonnet',
        systemPrompts: [],
        prompt: null,
        messages: [],
        maxSteps: 5,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: [],
        mcpServers: [$server1, $server2, $server3],
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: []
    );

    $mcpServers = $request->mcpServers();
    expect($mcpServers)->toHaveCount(3);
    expect($mcpServers[0])->toBe($server1);
    expect($mcpServers[1])->toBe($server2);
    expect($mcpServers[2])->toBe($server3);
});
