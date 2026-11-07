<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Tool;

dataset('OpenRouter reported billing', [
    'BYOK zero fee is not free inference' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.004, true, true],
    'BYOK fee plus upstream' => [['is_byok' => true, 'cost' => 0.0002, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.0042, true, true],
    'BYOK explicit all-zero positive control' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0]], 0.0, true, true],
    'non-BYOK zero positive control' => [['is_byok' => false, 'cost' => 0], 0.0, true, false],
    'non-BYOK must not double count upstream' => [['is_byok' => false, 'cost' => 0.004, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.004, true, false],
    'missing identity preserves legacy scalar without completeness' => [['cost' => 0.004], 0.004, false, null],
    'malformed identity never claims completeness' => [['is_byok' => 'true', 'cost' => 0], 0.0, false, null],
    'missing upstream is unknown' => [['is_byok' => true, 'cost' => 0], null, false, true],
    'null upstream is unknown' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => null]], null, false, true],
    'negative upstream is unknown' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => -0.004]], null, false, true],
    'nonnumeric upstream is unknown' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 'invalid']], null, false, true],
    'missing router charge is unknown' => [['is_byok' => true, 'cost_details' => ['upstream_inference_cost' => 0.004]], null, false, true],
    'null router charge is unknown' => [['is_byok' => true, 'cost' => null, 'cost_details' => ['upstream_inference_cost' => 0.004]], null, false, true],
    'negative router charge is unknown' => [['is_byok' => true, 'cost' => -0.004, 'cost_details' => ['upstream_inference_cost' => 0.004]], null, false, true],
    'invalid router charge is unknown' => [['is_byok' => false, 'cost' => 'invalid'], null, false, false],
    'overflowing BYOK sum is unknown' => [['is_byok' => true, 'cost' => 1e308, 'cost_details' => ['upstream_inference_cost' => 1e308]], null, false, true],
]);

it('maps full cost and validated provenance through the real handler', function (string $kind, array $billing, ?float $expected, bool $complete, ?bool $byok): void {
    $usage = ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110,
        'prompt_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 5],
        'completion_tokens_details' => ['reasoning_tokens' => 3], ...$billing];
    $body = ['id' => 'owned-billing-fixture', 'model' => 'openai/billing-fixture', 'usage' => $usage,
        'choices' => [['index' => 0, 'finish_reason' => 'stop', $kind === 'stream' ? 'delta' : 'message' => ['role' => 'assistant', 'content' => '{"answer":"fixture"}']]]];
    Http::fake(['*' => $kind === 'stream'
        ? Http::response('data: '.json_encode($body, JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])
        : Http::response($body)])->preventStrayRequests();

    if ($kind === 'structured') {
        $response = Prism::structured()->using(Provider::OpenRouter, 'openai/billing-fixture')->withPrompt('fixture')
            ->withSchema(new ObjectSchema('reply', 'fixture', [new StringSchema('answer', 'fixture')], ['answer']))->asStructured();
        $views = [$response, $response->steps->sole()];
    } else {
        $pending = Prism::text()->using(Provider::OpenRouter, 'openai/billing-fixture')->withPrompt('fixture');
        if ($kind === 'stream') {
            $events = collect(iterator_to_array($pending->asStream(), false));
            $views = [$events->whereInstanceOf(StepFinishEvent::class)->sole(), $events->whereInstanceOf(StreamEndEvent::class)->sole()];
        } else {
            $response = $pending->asText();
            $views = [$response, $response->steps->sole()];
        }
    }
    foreach ($views as $view) {
        if ($expected === null) {
            expect($view->usage->cost)->toBeNull();
        } else {
            expect($view->usage->cost)->toEqualWithDelta($expected, 1e-12);
        }
        expect($view->usage->promptTokens)->toBe(75)
            ->and($view->usage->completionTokens)->toBe(10)
            ->and($view->usage->cacheReadInputTokens)->toBe(20)
            ->and($view->usage->cacheWriteInputTokens)->toBe(5)
            ->and($view->usage->thoughtTokens)->toBe(3);
        if (! $view instanceof StreamEndEvent) {
            expect($view->additionalContent['openrouter_billing']['complete'])->toBe($complete)
                ->and($view->additionalContent['openrouter_billing']['is_byok'])->toBe($byok)
                ->and(array_keys($view->additionalContent['openrouter_billing']))->toBe(['is_byok', 'router_cost', 'upstream_cost', 'complete']);
        } else {
            expect($view->additionalContent)->not->toHaveKey('openrouter_billing');
        }
        if ($view instanceof StepFinishEvent || $view instanceof StreamEndEvent) {
            expect(json_encode($view->toArray()))->not->toContain('openrouter_billing')
                ->not->toContain('upstream')->not->toContain('is_byok')->not->toContain('cost_details');
        }
    }
    Http::assertSentCount(1);
})->with(['text', 'structured', 'stream'])->with('OpenRouter reported billing');

/** @param array<string, mixed>|null $billing
 * @return array<string, mixed>
 */
function openRouterBillingBody(?array $billing, bool $tool, bool $stream): array
{
    $message = ['role' => 'assistant', 'content' => $tool ? '' : '{"answer":"fixture"}'];
    if ($tool) {
        $message['tool_calls'] = [['index' => 0, 'id' => 'owned-billing-call', 'type' => 'function',
            'function' => ['name' => 'owned_billing_tool', 'arguments' => '{}']]];
    }
    $body = ['id' => 'owned-billing-fixture', 'model' => 'openai/billing-fixture',
        'choices' => [['index' => 0, 'finish_reason' => $tool ? 'tool_calls' : 'stop', $stream ? 'delta' : 'message' => $message]]];
    if ($billing !== null) {
        $body['usage'] = ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110, ...$billing];
    }

    return $body;
}

it('aggregates billing across real tool turns without trusting only the final step', function (string $kind, ?array $first, ?float $expected, bool $complete, ?bool $identity): void {
    $stream = $kind === 'stream';
    $last = ['is_byok' => true, 'cost' => 0.0001, 'cost_details' => ['upstream_inference_cost' => 0.002]];
    $frames = [openRouterBillingBody($first, true, $stream), openRouterBillingBody($last, false, $stream)];
    $sequence = Http::sequence();
    foreach ($frames as $frame) {
        $sequence->push($stream ? 'data: '.json_encode($frame, JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n" : $frame,
            200, $stream ? ['Content-Type' => 'text/event-stream'] : []);
    }
    Http::fake(['*' => $sequence])->preventStrayRequests();
    $calls = 0;
    $tool = (new Tool)->as('owned_billing_tool')->for('Owned harmless fixture')->using(function () use (&$calls): string {
        $calls++;

        return 'fixture tool result';
    });
    if ($kind === 'structured') {
        $response = Prism::structured()->using(Provider::OpenRouter, 'openai/billing-fixture')->withPrompt('fixture')
            ->withTools([$tool])->withMaxSteps(2)
            ->withSchema(new ObjectSchema('reply', 'fixture', [new StringSchema('answer', 'fixture')], ['answer']))->asStructured();
        $steps = $response->steps;
    } else {
        $pending = Prism::text()->using(Provider::OpenRouter, 'openai/billing-fixture')->withPrompt('fixture')->withTools([$tool])->withMaxSteps(2);
        if ($stream) {
            $events = collect(iterator_to_array($pending->asStream(), false));
            expect($events->whereInstanceOf(ErrorEvent::class)->map(fn ($event): array => $event->toArray())->values()->all())->toBe([]);
            $steps = $events->whereInstanceOf(StepFinishEvent::class)->values();
            $response = $events->whereInstanceOf(StreamEndEvent::class)->sole();
        } else {
            $response = $pending->asText();
            $steps = $response->steps;
        }
    }
    expect($calls)->toBe(1)->and($steps)->toHaveCount(2);
    if ($expected === null) {
        expect($response->usage->cost)->toBeNull();
    } else {
        expect($response->usage->cost)->toEqualWithDelta($expected, 1e-12);
    }
    if ($stream) {
        expect($response->additionalContent)->not->toHaveKey('openrouter_billing')
            ->and($steps->first()->additionalContent['openrouter_billing']['complete'])->toBe($complete);
    } else {
        expect($response->additionalContent['openrouter_billing']['complete'])->toBe($complete)
            ->and($response->additionalContent['openrouter_billing']['is_byok'])->toBe($identity);
    }
    expect($steps->last()->additionalContent['openrouter_billing']['complete'])->toBeTrue()
        ->and($steps->last()->usage->cost)->toEqualWithDelta(0.0021, 1e-12);
    Http::assertSentCount(2);
})->with(['text', 'structured', 'stream'])->with([
    'both calls fully measured' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.0061, true, true],
    'first call missing upstream' => [['is_byok' => true, 'cost' => 0], null, false, true],
    'first call has no usage' => [null, null, false, null],
    'legacy first call has no identity' => [['cost' => 0.001], 0.0031, false, null],
    'routing changes from credits to BYOK' => [['is_byok' => false, 'cost' => 0.001], 0.0031, true, null],
]);

it('keeps billing provenance on approval-paused stream finishes', function (): void {
    $body = openRouterBillingBody(['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0.004]], true, true);
    Http::fake(['*' => Http::response('data: '.json_encode($body, JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n", 200,
        ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();
    $calls = 0;
    $tool = (new Tool)->as('owned_billing_tool')->for('Never execute without approval')->using(function () use (&$calls): string {
        $calls++;

        return 'not authorized';
    })->requiresApproval();
    $events = collect(iterator_to_array(Prism::text()->using(Provider::OpenRouter, 'openai/billing-fixture')->withPrompt('fixture')->withTools([$tool])->asStream(), false));
    expect($calls)->toBe(0);
    foreach ([$events->whereInstanceOf(StepFinishEvent::class)->sole(), $events->whereInstanceOf(StreamEndEvent::class)->sole()] as $event) {
        expect($event->usage->cost)->toBe(0.004);
        if ($event instanceof StepFinishEvent) {
            expect($event->additionalContent['openrouter_billing']['complete'])->toBeTrue();
        } else {
            expect($event->additionalContent)->not->toHaveKey('openrouter_billing');
        }
    }
    Http::assertSentCount(1);
});

it('keeps BYOK billing on PHP objects and out of the real SSE output', function (): void {
    $body = openRouterBillingBody(['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0.004]], false, true);
    Http::fake(['*' => Http::response('data: '.json_encode($body, JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n", 200,
        ['Content-Type' => 'text/event-stream'])])->preventStrayRequests();
    $complete = null;
    $response = Prism::text()->using(Provider::OpenRouter, 'openai/billing-fixture')->withPrompt('fixture')
        ->asEventStreamResponse(function ($pending, $events) use (&$complete): void {
            $complete = $events->whereInstanceOf(StepFinishEvent::class)->sole()->additionalContent['openrouter_billing']['complete'];
        });
    $wire = '';
    ob_start(function (string $chunk) use (&$wire): string {
        $wire .= $chunk;

        return '';
    });
    try {
        ($response->getCallback())();
    } finally {
        ob_end_flush();
    }
    expect($complete)->toBeTrue()->and($wire)->toContain('stream_end')->toContain('fixture')
        ->not->toContain('openrouter_billing')->not->toContain('upstream')->not->toContain('is_byok')->not->toContain('cost_details');
    Http::assertSentCount(1);
});
