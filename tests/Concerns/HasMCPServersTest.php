<?php

declare(strict_types=1);

use Prism\Prism\Concerns\HasMCPServers;
use Prism\Prism\ValueObjects\MCPServer;

// Create a test class that uses the trait
class TestClassWithMCPServers
{
    use HasMCPServers;
}

beforeEach(function (): void {
    $this->testClass = new TestClassWithMCPServers;
});

it('starts with empty MCP servers', function (): void {
    expect($this->testClass->getMCPServers())->toBe([]);
    expect($this->testClass->hasMCPServers())->toBeFalse();
});

it('can add a single MCP server with withMCPServer', function (): void {
    $result = $this->testClass->withMCPServer(
        name: 'test-server',
        url: 'https://test.com',
        authorizationToken: 'token',
        toolConfiguration: ['config' => 'value']
    );

    expect($result)->toBe($this->testClass); // Returns self for chaining
    expect($this->testClass->hasMCPServers())->toBeTrue();

    $servers = $this->testClass->getMCPServers();
    expect($servers)->toHaveCount(1);
    expect($servers[0])->toBeInstanceOf(MCPServer::class);
    expect($servers[0]->name)->toBe('test-server');
    expect($servers[0]->url)->toBe('https://test.com');
    expect($servers[0]->authorizationToken)->toBe('token');
    expect($servers[0]->toolConfiguration)->toBe(['config' => 'value']);
});

it('can add MCP server with minimal parameters', function (): void {
    $this->testClass->withMCPServer('minimal', 'https://minimal.com');

    $servers = $this->testClass->getMCPServers();
    expect($servers)->toHaveCount(1);
    expect($servers[0]->name)->toBe('minimal');
    expect($servers[0]->url)->toBe('https://minimal.com');
    expect($servers[0]->authorizationToken)->toBeNull();
    expect($servers[0]->toolConfiguration)->toBe([]);
});

it('can chain multiple withMCPServer calls', function (): void {
    $result = $this->testClass
        ->withMCPServer('server1', 'https://server1.com')
        ->withMCPServer('server2', 'https://server2.com', 'token2');

    expect($result)->toBe($this->testClass);
    expect($this->testClass->getMCPServers())->toHaveCount(2);
    expect($this->testClass->getMCPServers()[0]->name)->toBe('server1');
    expect($this->testClass->getMCPServers()[1]->name)->toBe('server2');
});

it('can add multiple servers with withMCPServers using MCPServer objects', function (): void {
    $server1 = new MCPServer('obj1', 'https://obj1.com');
    $server2 = new MCPServer('obj2', 'https://obj2.com', 'token');

    $result = $this->testClass->withMCPServers([$server1, $server2]);

    expect($result)->toBe($this->testClass);
    expect($this->testClass->getMCPServers())->toHaveCount(2);
    expect($this->testClass->getMCPServers()[0])->toBe($server1);
    expect($this->testClass->getMCPServers()[1])->toBe($server2);
});

it('can add multiple servers with withMCPServers using arrays', function (): void {
    $servers = [
        [
            'name' => 'array1',
            'url' => 'https://array1.com',
            'authorization_token' => 'token1',
            'tool_configuration' => ['setting' => 'value1'],
        ],
        [
            'name' => 'array2',
            'url' => 'https://array2.com',
        ],
    ];

    $result = $this->testClass->withMCPServers($servers);

    expect($result)->toBe($this->testClass);
    expect($this->testClass->getMCPServers())->toHaveCount(2);

    $mcpServers = $this->testClass->getMCPServers();
    expect($mcpServers[0]->name)->toBe('array1');
    expect($mcpServers[0]->authorizationToken)->toBe('token1');
    expect($mcpServers[0]->toolConfiguration)->toBe(['setting' => 'value1']);

    expect($mcpServers[1]->name)->toBe('array2');
    expect($mcpServers[1]->authorizationToken)->toBeNull();
});

it('can mix MCPServer objects and arrays in withMCPServers', function (): void {
    $serverObj = new MCPServer('object', 'https://object.com');
    $serverArray = [
        'name' => 'array',
        'url' => 'https://array.com',
        'authorization_token' => 'array-token',
    ];

    $this->testClass->withMCPServers([$serverObj, $serverArray]);

    $servers = $this->testClass->getMCPServers();
    expect($servers)->toHaveCount(2);
    expect($servers[0])->toBe($serverObj);
    expect($servers[1]->name)->toBe('array');
    expect($servers[1]->authorizationToken)->toBe('array-token');
});

it('accumulates servers across multiple calls', function (): void {
    $this->testClass
        ->withMCPServer('single', 'https://single.com')
        ->withMCPServers([
            ['name' => 'batch1', 'url' => 'https://batch1.com'],
            ['name' => 'batch2', 'url' => 'https://batch2.com'],
        ])
        ->withMCPServer('another', 'https://another.com');

    expect($this->testClass->getMCPServers())->toHaveCount(4);
    expect($this->testClass->getMCPServers()[0]->name)->toBe('single');
    expect($this->testClass->getMCPServers()[1]->name)->toBe('batch1');
    expect($this->testClass->getMCPServers()[2]->name)->toBe('batch2');
    expect($this->testClass->getMCPServers()[3]->name)->toBe('another');
});
