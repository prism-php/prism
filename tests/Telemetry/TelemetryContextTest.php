<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Concerns\EmitsTelemetry;
use Prism\Prism\Concerns\HasTelemetryContext;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Prism as PrismClass;
use Prism\Prism\Telemetry\Events\SpanException;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Text\Response;

class TestContextClass
{
    use EmitsTelemetry, HasTelemetryContext;

    public function getTelemetryContext(): array
    {
        return $this->telemetryContext;
    }

    public function testPushContext(): void
    {
        $this->pushTelemetryContext();
    }
}

class TestExceptionClass
{
    use EmitsTelemetry, HasTelemetryContext;

    public function executeWithException(): void
    {
        $this->withTelemetry(
            startEventFactory: fn ($spanId, $traceId, $parentSpanId): object => new class($spanId)
            {
                public function __construct(public string $spanId) {}
            },
            endEventFactory: fn ($spanId, $traceId, $parentSpanId, $response): object => new class($spanId)
            {
                public function __construct(public string $spanId) {}
            },
            execute: fn () => throw new RuntimeException('Test exception from execute'),
        );
    }
}

beforeEach(function (): void {
    Context::forgetHidden('prism.telemetry.metadata');
    Context::forgetHidden('prism.telemetry.trace_id');
    Context::forgetHidden('prism.telemetry.current_span_id');
    PrismClass::flushDefaultTelemetryContext();
});

describe('HasTelemetryContext fluent methods', function (): void {
    it('sets user context with forUser method', function (): void {
        $instance = new TestContextClass;

        $result = $instance->forUser('user-123', 'test@example.com');

        expect($result)->toBe($instance)
            ->and($instance->getTelemetryContext())->toBe([
                'user_id' => 'user-123',
                'user_email' => 'test@example.com',
            ]);
    });

    it('converts integer user id to string', function (): void {
        $instance = (new TestContextClass)->forUser(123);

        expect($instance->getTelemetryContext()['user_id'])->toBe('123');
    });

    it('sets conversation context with forConversation method', function (): void {
        $instance = (new TestContextClass)->forConversation('conv-456');

        expect($instance->getTelemetryContext())->toBe(['session_id' => 'conv-456']);
    });

    it('sets agent context with forAgent method', function (): void {
        $instance = (new TestContextClass)->forAgent('support-bot');

        expect($instance->getTelemetryContext())->toBe(['agent' => 'support-bot']);
    });

    it('sets arbitrary context with withTelemetryContext method', function (): void {
        $instance = (new TestContextClass)->withTelemetryContext([
            'custom_key' => 'custom_value',
            'environment' => 'production',
        ]);

        expect($instance->getTelemetryContext())->toBe([
            'custom_key' => 'custom_value',
            'environment' => 'production',
        ]);
    });

    it('sets tags with withTelemetryTags method', function (): void {
        $instance = (new TestContextClass)->withTelemetryTags([
            'priority' => 'high',
            'department' => 'sales',
        ]);

        expect($instance->getTelemetryContext()['tags'])->toBe([
            'priority' => 'high',
            'department' => 'sales',
        ]);
    });

    it('chains multiple context methods together', function (): void {
        $instance = (new TestContextClass)
            ->forUser('user-123')
            ->forConversation('conv-456')
            ->forAgent('code-assistant')
            ->withTelemetryTags(['env' => 'test']);

        expect($instance->getTelemetryContext())->toBe([
            'user_id' => 'user-123',
            'session_id' => 'conv-456',
            'agent' => 'code-assistant',
            'tags' => ['env' => 'test'],
        ]);
    });
});

describe('Laravel Context integration', function (): void {
    it('pushes context to Laravel hidden context', function (): void {
        $instance = (new TestContextClass)->forUser('user-789')->forAgent('test-agent');
        $instance->testPushContext();

        expect(Context::getHidden('prism.telemetry.metadata'))->toBe([
            'user_id' => 'user-789',
            'agent' => 'test-agent',
        ]);
    });
});

describe('global default context', function (): void {
    it('sets and retrieves global default telemetry context', function (): void {
        PrismClass::defaultTelemetryContext(fn (): array => [
            'environment' => 'testing',
            'app_version' => '1.0.0',
        ]);

        expect(PrismClass::getDefaultTelemetryContext())->toBe([
            'environment' => 'testing',
            'app_version' => '1.0.0',
        ]);
    });

    it('flushes global default telemetry context', function (): void {
        PrismClass::defaultTelemetryContext(fn (): array => ['key' => 'value']);
        PrismClass::flushDefaultTelemetryContext();

        expect(PrismClass::getDefaultTelemetryContext())->toBe([]);
    });

    it('evaluates resolver lazily on each call', function (): void {
        $callCount = 0;
        PrismClass::defaultTelemetryContext(function () use (&$callCount): array {
            $callCount++;

            return ['call_count' => $callCount];
        });

        PrismClass::getDefaultTelemetryContext();
        PrismClass::getDefaultTelemetryContext();

        expect($callCount)->toBe(2);
    });
});

describe('context merging', function (): void {
    it('merges global default context with instance context', function (): void {
        PrismClass::defaultTelemetryContext(fn (): array => [
            'environment' => 'testing',
            'default_key' => 'default_value',
        ]);

        $instance = (new TestContextClass)->forUser('user-123');
        $instance->testPushContext();

        expect(Context::getHidden('prism.telemetry.metadata'))->toBe([
            'environment' => 'testing',
            'default_key' => 'default_value',
            'user_id' => 'user-123',
        ]);
    });

    it('instance context overrides global default context', function (): void {
        PrismClass::defaultTelemetryContext(fn (): array => [
            'user_id' => 'global-user',
            'environment' => 'production',
        ]);

        $instance = (new TestContextClass)->forUser('specific-user');
        $instance->testPushContext();

        $metadata = Context::getHidden('prism.telemetry.metadata');
        expect($metadata['user_id'])->toBe('specific-user')
            ->and($metadata['environment'])->toBe('production');
    });
});

describe('context flows to telemetry', function (): void {
    it('context metadata flows through to telemetry events', function (): void {
        config(['prism.telemetry.enabled' => true]);
        Event::fake();

        PrismClass::defaultTelemetryContext(fn (): array => ['environment' => 'test']);

        $mockResponse = new Response(
            steps: collect(),
            text: 'Test response',
            finishReason: \Prism\Prism\Enums\FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new \Prism\Prism\ValueObjects\Usage(10, 20),
            meta: new \Prism\Prism\ValueObjects\Meta('test-id', 'test-model'),
            messages: collect()
        );

        Prism::fake([$mockResponse]);

        Prism::text()
            ->using('openai', 'gpt-4')
            ->forUser('user-integration-test')
            ->forAgent('integration-agent')
            ->withPrompt('Test prompt')
            ->asText();

        Event::assertDispatched(TextGenerationStarted::class);
        Event::assertDispatched(TextGenerationCompleted::class);
    });
});

describe('exception handling', function (): void {
    it('dispatches SpanException when operation throws', function (): void {
        config(['prism.telemetry.enabled' => true]);
        Event::fake();

        $testClass = new TestExceptionClass;

        try {
            $testClass->executeWithException();
            $this->fail('Expected RuntimeException to be thrown');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Test exception from execute');
        }

        Event::assertDispatched(SpanException::class, fn ($e): bool => $e->exception instanceof RuntimeException
                && $e->exception->getMessage() === 'Test exception from execute'
                && ! empty($e->spanId));
    });

    it('resets span context even when exception is thrown', function (): void {
        config(['prism.telemetry.enabled' => true]);
        Event::fake();

        $parentSpanId = 'parent-span-exception-test';
        Context::addHidden('prism.telemetry.trace_id', 'trace-exception');
        Context::addHidden('prism.telemetry.current_span_id', $parentSpanId);

        $testClass = new TestExceptionClass;

        try {
            $testClass->executeWithException();
        } catch (RuntimeException) {
            // Expected
        }

        expect(Context::getHidden('prism.telemetry.current_span_id'))->toBe($parentSpanId);
    });
});
