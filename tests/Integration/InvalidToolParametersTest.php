<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', env('ANTHROPIC_API_KEY', 'sk-1234'));
});

it('throws exception when AI provides invalid parameters to tool', function (): void {
    // Create a tool that expects specific parameters
    $readFileTool = (new Tool)
        ->as('read_file')
        ->for('Read a file with optional line range')
        ->withStringParameter('path', 'The file path to read')
        ->withNumberParameter('start_line', 'Starting line number', required: false)
        ->withNumberParameter('end_line', 'Ending line number', required: false)
        ->using(function (string $path, ?int $start_line = null, ?int $end_line = null): string {
            // This simulates a real file reading function
            if (! file_exists($path)) {
                throw new \InvalidArgumentException("File not found: $path");
            }

            return 'File contents here...';
        });

    // Fake the AI response with invalid parameters (passing strings instead of numbers)
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_01',
                        'name' => 'read_file',
                        'input' => [
                            'path' => 'resources/views/test.blade.php',
                            'start_line' => '37', // String instead of number - this will cause an error
                            'end_line' => '43',   // String instead of number - this will cause an error
                        ],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'stop_sequence' => null,
                'usage' => [
                    'input_tokens' => 50,
                    'output_tokens' => 25,
                ],
            ]),
    ]);

    // Set up Prism with the tool
    $prism = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20241022')
        ->withSystemPrompt('You are a helpful assistant.')
        ->withMessages([new UserMessage('Read lines 37-43 from the test.blade.php file')])
        ->withTools([$readFileTool]);

    // This will fail and break the flow
    expect(fn (): \Prism\Prism\Text\Response => $prism->asText())
        ->toThrow(PrismException::class, 'Invalid parameters for tool : read_file');
});

it('demonstrates the need for graceful error handling', function (): void {
    // Create a tool that will receive wrong parameter types
    $mathTool = (new Tool)
        ->as('calculate')
        ->for('Perform a calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->withStringParameter('operation', 'The operation to perform')
        ->using(fn (int $a, int $b, string $operation): string => match ($operation) {
            'add' => (string) ($a + $b),
            'subtract' => (string) ($a - $b),
            default => throw new \InvalidArgumentException("Unknown operation: $operation"),
        });

    // Simulate multiple tool calls where one has invalid parameters
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_01',
                        'name' => 'calculate',
                        'input' => [
                            'a' => 5,
                            'b' => 3,
                            'operation' => 'add',
                        ],
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_02',
                        'name' => 'calculate',
                        'input' => [
                            'a' => 'ten', // Invalid: string instead of number
                            'b' => 'five', // Invalid: string instead of number
                            'operation' => 'subtract',
                        ],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'stop_sequence' => null,
                'usage' => [
                    'input_tokens' => 50,
                    'output_tokens' => 25,
                ],
            ]),
    ]);

    $prism = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20241022')
        ->withMessages([new UserMessage('Calculate 5+3 and then ten minus five')])
        ->withTools([$mathTool]);

    // The first tool call would succeed, but the second fails
    // This breaks the entire flow even though we could potentially handle it gracefully
    expect(fn (): \Prism\Prism\Text\Response => $prism->asText())->toThrow(PrismException::class);
});

it('shows how missing required parameters break the flow', function (): void {
    $searchTool = (new Tool)
        ->as('search')
        ->for('Search for files')
        ->withStringParameter('query', 'Search query')
        ->withStringParameter('path', 'Directory to search in')
        ->withNumberParameter('limit', 'Max results', required: false)
        ->using(fn (string $query, string $path, ?int $limit = 10): string => "Found 5 results for '$query' in $path");

    // AI forgets to provide required 'path' parameter
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_01',
                        'name' => 'search',
                        'input' => [
                            'query' => 'test files', // Missing required 'path' parameter
                        ],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'stop_sequence' => null,
                'usage' => [
                    'input_tokens' => 50,
                    'output_tokens' => 25,
                ],
            ]),
    ]);

    $prism = Prism::text()
        ->using('anthropic', 'claude-3-5-sonnet-20241022')
        ->withMessages([new UserMessage('Search for test files')])
        ->withTools([$searchTool]);

    expect(fn (): \Prism\Prism\Text\Response => $prism->asText())->toThrow(PrismException::class);
});

it('demonstrates that error handling works same way for streaming and non-streaming', function (): void {
    // Create a tool that will get invalid parameters
    $mathTool = (new Tool)
        ->as('calculate')
        ->for('Perform a calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    // Test 1: Without error handling - both modes throw exception
    $invalidParams = ['a' => 'five', 'b' => 10];

    expect(fn (): string => $mathTool->handle(...$invalidParams))
        ->toThrow(PrismException::class, 'Invalid parameters for tool : calculate');

    // Test 2: With error handling - both modes return error message
    $mathToolWithErrorHandling = (new Tool)
        ->as('calculate')
        ->for('Perform a calculation')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int $a, int $b): string => (string) ($a + $b))
        ->handleToolErrors();

    $result = $mathToolWithErrorHandling->handle(...$invalidParams);
    expect($result)
        ->toContain('Parameter validation error: Type mismatch')
        ->toContain('Expected: [a (NumberSchema, required), b (NumberSchema, required)]');

    // The same error handling behavior applies whether used in streaming or non-streaming mode
});
