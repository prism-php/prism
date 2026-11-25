<?php

declare(strict_types=1);

namespace Tests\Providers\ModelsLab;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.modelslab.api_key', 'test-api-key');
});

it('can generate text with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('v7/llm/chat/completions', 'modelslab/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $events = [];

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)
        ->not->toBeEmpty()
        ->and($text)->not->toBeEmpty()
        ->and($text)->toContain('Hello! I\'m a helpful AI assistant.');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);
});

it('emits step start and step finish events', function (): void {
    FixtureResponse::fakeStreamResponses('v7/llm/chat/completions', 'modelslab/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Who are you?')
        ->asStream();

    $events = [];
    foreach ($response as $event) {
        $events[] = $event;
    }

    $stepStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StepStartEvent);
    $stepFinishEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StepFinishEvent);

    expect($stepStartEvents)->toHaveCount(1);
    expect($stepFinishEvents)->toHaveCount(1);
});

it('includes correct token counts in StreamEndEvent', function (): void {
    FixtureResponse::fakeStreamResponses('v7/llm/chat/completions', 'modelslab/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Who are you?')
        ->asStream();

    $streamEndEvent = null;

    foreach ($response as $event) {
        if ($event instanceof StreamEndEvent) {
            $streamEndEvent = $event;
        }
    }

    expect($streamEndEvent)->not->toBeNull();
    expect($streamEndEvent->usage->promptTokens)->toBe(10);
    expect($streamEndEvent->usage->completionTokens)->toBe(20);
});

it('emits only one StreamStartEvent and StreamEndEvent', function (): void {
    FixtureResponse::fakeStreamResponses('v7/llm/chat/completions', 'modelslab/stream-basic-text');

    $response = Prism::text()
        ->using(Provider::ModelsLab, 'llama-3.3-70b')
        ->withPrompt('Who are you?')
        ->asStream();

    $events = [];
    foreach ($response as $event) {
        $events[] = $event;
    }

    $streamStartEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamStartEvent);
    $streamEndEvents = array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent);

    expect($streamStartEvents)->toHaveCount(1);
    expect($streamEndEvents)->toHaveCount(1);
});
