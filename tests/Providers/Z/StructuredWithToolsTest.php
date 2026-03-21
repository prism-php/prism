<?php

declare(strict_types=1);

namespace Tests\Providers\Z;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.z.api_key', env('Z_API_KEY', 'zai-123'));
});

describe('Structured output with tools for Z', function (): void {
    it('stops execution when client-executed tool is called', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/text-with-client-executed-tool');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $tool = (new Tool)
            ->as('client_tool')
            ->for('A tool that executes on the client')
            ->withStringParameter('input', 'Input parameter')
            ->clientExecuted();

        $response = Prism::structured()
            ->using(Provider::Z, 'z-model')
            ->withSchema($schema)
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withPrompt('Use the client tool')
            ->asStructured();

        expect($response->finishReason)->toBe(FinishReason::ToolCalls);
        expect($response->toolCalls)->toHaveCount(1);
        expect($response->toolCalls[0]->name)->toBe('client_tool');
        expect($response->steps)->toHaveCount(1);
    });

    it('stops execution when approval-required tool is called', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/text-with-approval-tool');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file. Requires user approval.')
            ->withStringParameter('path', 'File path to delete')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $response = Prism::structured()
            ->using(Provider::Z, 'z-model')
            ->withSchema($schema)
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withPrompt('Delete /tmp/test.txt')
            ->asStructured();

        expect($response->finishReason)->toBe(FinishReason::ToolCalls);
        expect($response->toolCalls)->toHaveCount(1);
        expect($response->toolCalls[0]->name)->toBe('delete_file');
        expect($response->steps)->toHaveCount(1);
    });

    it('executes approved tool and returns structured output (Phase 2)', function (): void {
        FixtureResponse::fakeResponseSequence('chat/completions', 'z/structured-with-approval-phase2');

        $schema = new ObjectSchema(
            'output',
            'the output object',
            [new StringSchema('result', 'The result', true)],
            ['result']
        );

        $tool = (new Tool)
            ->as('delete_file')
            ->for('Delete a file. Requires user approval.')
            ->withStringParameter('path', 'File path to delete')
            ->using(fn (string $path): string => "Deleted: {$path}")
            ->requiresApproval();

        $response = Prism::structured()
            ->using(Provider::Z, 'z-model')
            ->withSchema($schema)
            ->withTools([$tool])
            ->withMaxSteps(3)
            ->withMessages([
                new UserMessage('Delete /tmp/test.txt'),
                new AssistantMessage(
                    content: '',
                    toolCalls: [
                        new ToolCall(id: 'call_delete_file', name: 'delete_file', arguments: ['path' => '/tmp/test.txt']),
                    ],
                    additionalContent: [],
                    toolApprovalRequests: [
                        new ToolApprovalRequest(approvalId: 'apr_call_delete_file', toolCallId: 'call_delete_file'),
                    ],
                ),
                new ToolResultMessage([], [
                    new ToolApprovalResponse(approvalId: 'apr_call_delete_file', approved: true),
                ]),
            ])
            ->asStructured();

        expect($response->structured)->toBeArray()
            ->and($response->structured)->toHaveKey('result')
            ->and($response->structured['result'])->toContain('deleted');
        expect($response->finishReason)->toBe(FinishReason::Stop);
    });
});
