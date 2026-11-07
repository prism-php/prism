<?php

declare(strict_types=1);

namespace Tests\Tools;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\Tools\LaravelMcpTool;

it('can handle a tool that returns a Response', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'test-tool';

        protected string $description = 'A test tool';

        public function handle(Request $request): Response
        {
            return Response::text('Hello, World!');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toBe('Hello, World!');
});

it('can handle a tool that returns a ResponseFactory from Response::structured()', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'structured-tool';

        protected string $description = 'A tool that returns structured data';

        public function handle(Request $request): ResponseFactory
        {
            return Response::structured([
                'status' => 'success',
                'data' => [
                    'id' => 123,
                    'name' => 'Test',
                ],
            ]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toContain('"status":"success"')
        ->and($result)->toContain('"id":123')
        ->and($result)->toContain('"name":"Test"');
});

it('can handle a tool that returns a ResponseFactory from Response::make()', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'make-tool';

        protected string $description = 'A tool that uses Response::make()';

        public function handle(Request $request): ResponseFactory
        {
            return Response::make([
                Response::text('First response'),
                Response::text('Second response'),
            ]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toBe("First response\nSecond response");
});

it('can handle a tool that returns a Generator of Responses', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'generator-tool';

        protected string $description = 'A tool that yields responses';

        /** @return \Generator<Response> */
        public function handle(Request $request): \Generator
        {
            yield Response::text('Yielded first');
            yield Response::text('Yielded second');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toBe("Yielded first\nYielded second");
});

it('excludes tools whose shouldRegister returns false', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'mcp-tool';

        protected string $description = 'An MCP tool without shouldRegister';

        public function handle(Request $request): Response
        {
            return Response::text('mcp');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $unregistered = new class extends McpTool
    {
        protected string $name = 'unregistered-tool';

        protected string $description = 'An unregistered tool';

        public function handle(Request $request): Response
        {
            return Response::text('unregistered');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function shouldRegister(): bool
        {
            return false;
        }
    };

    $fake = Prism::fake([TextResponseFake::make()]);

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withPrompt('Hello')
        ->withTools([
            Tool::as('plain-tool')->for('A plain Prism tool')->using(fn (): string => 'plain'),
            Tool::make($mcpTool),
            Tool::make($unregistered),
        ])
        ->asText();

    $fake->assertRequest(function (array $requests): void {
        $toolNames = array_map(fn (PrismTool $tool): string => $tool->name(), $requests[0]->tools());

        expect($toolNames)->toBe(['plain-tool', 'mcp-tool']);
    });
});

it('passes the MCP request to shouldRegister', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'subscribers-tool';

        protected string $description = 'A tool for authenticated users';

        public function handle(Request $request): Response
        {
            return Response::text('Hello');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function shouldRegister(Request $request): bool
        {
            return $request->user() instanceof Authenticatable;
        }
    };

    expect((new LaravelMcpTool($mcpTool))->eligibleForRegistration())->toBeFalse();

    Auth::setUser(new GenericUser(['id' => 1]));

    expect((new LaravelMcpTool($mcpTool))->eligibleForRegistration())->toBeTrue();
});
