<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool as PrismTool;
use RuntimeException;
use Tests\Fixtures\FixtureResponse;
use Throwable;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.azure.url', 'https://test-resource.openai.azure.com');
    config()->set('prism.providers.azure.api_key', 'azure-key-1234');
    config()->set('prism.providers.azure.api_version', '2024-10-21');
    config()->set('prism.providers.azure.deployment_name', 'gpt-4o');
});

function approvalCheckSse(string $arguments): string
{
    $chunk = fn (array $delta, ?string $finish = null): string => 'data: '.json_encode([
        'id' => 'chatcmpl-check',
        'object' => 'chat.completion.chunk',
        'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
    ])."\n\n";

    return $chunk(['role' => 'assistant', 'tool_calls' => [[
        'index' => 0, 'id' => 'call_1', 'type' => 'function',
        'function' => ['name' => 'delete_file', 'arguments' => $arguments],
    ]]])
        .$chunk([], 'tool_calls')
        .'data: [DONE]'."\n\n";
}

function approvalCheckDoneSse(): string
{
    return 'data: '.json_encode([
        'id' => 'chatcmpl-done', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'delta' => ['content' => 'Done.'], 'finish_reason' => 'stop']],
    ])."\n\n".'data: [DONE]'."\n\n";
}

function approvalCheckTool(int &$ran, Throwable $failure): PrismTool
{
    return Tool::as('delete_file')->for('Delete a file')->withStringParameter('path', 'Path')
        ->using(function (string $path) use (&$ran): string {
            $ran++;

            return 'deleted';
        })
        ->requiresApproval(function (array $arguments) use ($failure): bool {
            throw $failure;
        });
}

dataset('approval check failures', [
    'a Prism exception' => fn (): Throwable => new PrismException('approval check failed'),
    'a runtime exception' => fn (): Throwable => new RuntimeException('approval check failed'),
]);

it('stops a text call whose approval check fails, and runs nothing from that step', function (Throwable $failure): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/generate-text-with-multiple-tools');
    $weather = 0;
    $search = 0;

    $tools = [
        Tool::as('weather')->for('Weather')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$weather): string {
                $weather++;

                return 'sunny';
            })
            ->requiresApproval(function (array $arguments) use ($failure): bool {
                throw $failure;
            }),
        Tool::as('search')->for('Search')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$search): string {
                $search++;

                return 'found';
            }),
    ];

    $thrown = null;
    try {
        Prism::text()->using('openai', 'gpt-4o')->withTools($tools)->withMaxSteps(3)
            ->withPrompt('What time is the game, and should I wear a coat?')->asText();
    } catch (Throwable $caught) {
        $thrown = $caught;
    }

    expect($weather)->toBe(0)
        ->and($search)->toBe(0)
        ->and($thrown)->toBe($failure);
})->with('approval check failures');

it('stops a streamed call whose approval check fails', function (Throwable $failure): void {
    Http::fake(['*' => Http::response(approvalCheckSse('{"path":"a.txt"}'), 200, ['Content-Type' => 'text/event-stream'])])
        ->preventStrayRequests();
    $ran = 0;

    $thrown = null;
    try {
        iterator_to_array(Prism::text()->using(Provider::Azure, 'gpt-4o')
            ->withTools([approvalCheckTool($ran, $failure)])
            ->withMaxSteps(4)->withPrompt('Delete a.txt.')->asStream(), false);
    } catch (Throwable $caught) {
        $thrown = $caught;
    }

    expect($ran)->toBe(0)->and($thrown)->toBe($failure);
    Http::assertSentCount(1);
})->with('approval check failures');

it('still reports malformed arguments as a tool error without running the tool', function (): void {
    Http::fakeSequence()
        ->push(approvalCheckSse('{not json'), 200, ['Content-Type' => 'text/event-stream'])
        ->push(approvalCheckDoneSse(), 200, ['Content-Type' => 'text/event-stream']);
    $ran = 0;
    $asked = 0;

    $events = [];
    try {
        foreach (Prism::text()->using(Provider::Azure, 'gpt-4o')
            ->withTools([
                Tool::as('delete_file')->for('Delete a file')->withStringParameter('path', 'Path')
                    ->using(function (string $path) use (&$ran): string {
                        $ran++;

                        return 'deleted';
                    })
                    ->requiresApproval(function (array $arguments) use (&$asked): bool {
                        $asked++;

                        return false;
                    }),
            ])
            ->withMaxSteps(4)->withPrompt('Delete a.txt.')->asStream() as $event) {
            $events[] = $event;
        }
    } catch (PrismException) {
        // Sending the unparseable call back to the provider may fail later;
        // what matters here is the result recorded for it and that it never ran.
    }

    expect($ran)->toBe(0)
        ->and($asked)->toBe(0)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class)->sole()->success)->toBeFalse();
});

it('still reports an unknown tool through tool error handling', function (): void {
    Http::fakeSequence()
        ->push(str_replace('delete_file', 'no_such_tool', approvalCheckSse('{"path":"a.txt"}')), 200, ['Content-Type' => 'text/event-stream'])
        ->push(approvalCheckDoneSse(), 200, ['Content-Type' => 'text/event-stream']);
    $ran = 0;

    $events = collect(iterator_to_array(Prism::text()->using(Provider::Azure, 'gpt-4o')
        ->withTools([approvalCheckTool($ran, new RuntimeException('not consulted'))])
        ->withMaxSteps(4)->withPrompt('Delete a.txt.')->asStream(), false));

    expect($ran)->toBe(0)
        ->and($events->whereInstanceOf(ToolResultEvent::class)->sole()->success)->toBeFalse();
});
