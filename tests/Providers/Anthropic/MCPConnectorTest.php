<?php

declare(strict_types=1);

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Providers\Anthropic\Handlers\Structured;
use Prism\Prism\Providers\Anthropic\Handlers\Text;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\MCPServer;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'sk-test-key');
});

it('includes MCP servers in text request payload', function (): void {
    $mcpServer1 = new MCPServer('filesystem', 'https://fs-server.com', 'fs-token', ['readonly' => true]);
    $mcpServer2 = new MCPServer('database', 'https://db-server.com');

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
        mcpServers: [$mcpServer1, $mcpServer2],
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: []
    );

    $payload = Text::buildHttpRequestPayload($request);

    expect($payload)->toHaveKey('mcp_servers');
    expect($payload['mcp_servers'])->toHaveCount(2);

    expect($payload['mcp_servers'][0])->toBe([
        'type' => 'url',
        'url' => 'https://fs-server.com',
        'name' => 'filesystem',
        'authorization_token' => 'fs-token',
        'tool_configuration' => ['readonly' => true],
    ]);

    expect($payload['mcp_servers'][1])->toBe([
        'type' => 'url',
        'url' => 'https://db-server.com',
        'name' => 'database',
    ]);
});

it('includes MCP servers in structured request payload', function (): void {
    $mcpServer = new MCPServer('analytics', 'https://analytics.com', 'analytics-token');
    $schema = new ObjectSchema(
        'result',
        'The result object',
        [new StringSchema('data', 'The result data')]
    );

    $request = new StructuredRequest(
        systemPrompts: [],
        model: 'claude-3-sonnet',
        prompt: 'Analyze data',
        messages: [],
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

    $payload = Structured::buildHttpRequestPayload($request);

    expect($payload)->toHaveKey('mcp_servers');
    expect($payload['mcp_servers'])->toHaveCount(1);
    expect($payload['mcp_servers'][0])->toBe([
        'type' => 'url',
        'url' => 'https://analytics.com',
        'name' => 'analytics',
        'authorization_token' => 'analytics-token',
    ]);
});

it('does not include mcp_servers in payload when no servers are configured', function (): void {
    $request = new TextRequest(
        model: 'claude-3-sonnet',
        systemPrompts: [],
        prompt: 'Test without MCP',
        messages: [],
        maxSteps: 5,
        maxTokens: 1000,
        temperature: 0.7,
        topP: 0.9,
        tools: [],
        mcpServers: [], // Empty array
        clientOptions: [],
        clientRetry: [],
        toolChoice: null,
        providerOptions: []
    );

    $payload = Text::buildHttpRequestPayload($request);

    expect($payload)->not->toHaveKey('mcp_servers');
});

it('excludes mcp_servers from structured payload when empty', function (): void {
    $schema = new ObjectSchema(
        'result',
        'The result object',
        [new StringSchema('data', 'The result data')]
    );

    $request = new StructuredRequest(
        systemPrompts: [],
        model: 'claude-3-sonnet',
        prompt: 'Test without MCP',
        messages: [],
        maxTokens: 1000,
        temperature: 0.5,
        topP: 0.8,
        mcpServers: [], // Empty array
        clientOptions: [],
        clientRetry: [],
        schema: $schema,
        mode: StructuredMode::Auto,
        providerOptions: []
    );

    $payload = Structured::buildHttpRequestPayload($request);

    expect($payload)->not->toHaveKey('mcp_servers');
});
