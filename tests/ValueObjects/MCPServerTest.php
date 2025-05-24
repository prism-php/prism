<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\MCPServer;

it('can create an MCPServer with all parameters', function (): void {
    $mcpServer = new MCPServer(
        name: 'test-server',
        url: 'https://test-server.com',
        authorizationToken: 'test-token',
        toolConfiguration: ['setting' => 'value']
    );

    expect($mcpServer->name)->toBe('test-server');
    expect($mcpServer->url)->toBe('https://test-server.com');
    expect($mcpServer->authorizationToken)->toBe('test-token');
    expect($mcpServer->toolConfiguration)->toBe(['setting' => 'value']);
});

it('can create an MCPServer with minimal parameters', function (): void {
    $mcpServer = new MCPServer(
        name: 'minimal-server',
        url: 'https://minimal.com'
    );

    expect($mcpServer->name)->toBe('minimal-server');
    expect($mcpServer->url)->toBe('https://minimal.com');
    expect($mcpServer->authorizationToken)->toBeNull();
    expect($mcpServer->toolConfiguration)->toBe([]);
});

it('converts to array format correctly with all fields', function (): void {
    $mcpServer = new MCPServer(
        name: 'full-server',
        url: 'https://full-server.com',
        authorizationToken: 'full-token',
        toolConfiguration: ['config' => 'test']
    );

    $array = $mcpServer->toArray();

    expect($array)->toBe([
        'type' => 'url',
        'url' => 'https://full-server.com',
        'name' => 'full-server',
        'authorization_token' => 'full-token',
        'tool_configuration' => ['config' => 'test'],
    ]);
});

it('converts to array format correctly with minimal fields', function (): void {
    $mcpServer = new MCPServer(
        name: 'minimal-server',
        url: 'https://minimal.com'
    );

    $array = $mcpServer->toArray();

    expect($array)->toBe([
        'type' => 'url',
        'url' => 'https://minimal.com',
        'name' => 'minimal-server',
    ]);
});

it('excludes null authorization token from array', function (): void {
    $mcpServer = new MCPServer(
        name: 'no-auth',
        url: 'https://no-auth.com',
        authorizationToken: null
    );

    $array = $mcpServer->toArray();

    expect($array)->not->toHaveKey('authorization_token');
});

it('excludes empty tool configuration from array', function (): void {
    $mcpServer = new MCPServer(
        name: 'no-config',
        url: 'https://no-config.com',
        toolConfiguration: []
    );

    $array = $mcpServer->toArray();

    expect($array)->not->toHaveKey('tool_configuration');
});
