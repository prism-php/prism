<?php

declare(strict_types=1);

use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Text\Request;

/** @return array<string, mixed> */
function g203GeneratorObservation(bool $telemetry, string $scenario): array
{
    config(['prism.telemetry.enabled' => $telemetry]);
    $trace = new ArrayObject;
    foreach ([GenerationStarted::class => 'started', GenerationCompleted::class => 'completed', GenerationFailed::class => 'failed'] as $class => $label) {
        app('events')->forget($class);
        app('events')->listen($class, function () use ($trace, $label): void {
            $trace[] = $label;
        });
    }
    $failure = new LogicException('owned-consumer');
    $provider = new class($trace, $scenario, $failure) extends Provider
    {
        public function __construct(private ArrayObject $trace, private readonly string $scenario, private readonly Throwable $failure) {}

        public function stream(Request $request): Generator
        {
            $this->trace[] = 'inner-start';
            try {
                $sent = yield 'begin' => new TextDeltaEvent('first', 0, 'ready', 'owned');
                $this->trace[] = ['sent' => $sent];
                try {
                    yield 7 => new TextDeltaEvent('second', 0, 'next', 'owned');
                } catch (LogicException $failure) {
                    $this->trace[] = ['inner-caught' => $failure === $this->failure];
                    if ($this->scenario === 'caught') {
                        yield 'caught' => new TextDeltaEvent('caught', 0, 'recovered', 'owned');
                    } else {
                        throw $failure;
                    }
                }
                if ($this->scenario === 'innerthrow') {
                    throw $this->failure;
                }

                return 'owned-inner-return';
            } finally {
                $this->trace[] = ['inner-finally-active-context' => Telemetry::current() instanceof TelemetryContext];
            }
        }
    };
    resolve('prism-manager')->extend('owned-generator-probe', fn (): Provider => $provider);
    $stream = Prism::text()->using('owned-generator-probe', 'owned')->withPrompt('owned')->asStream();
    $keys = [];
    $ids = [];
    $caught = null;
    $return = 'not-exhausted';
    try {
        if ($scenario === 'break') {
            foreach ($stream as $key => $event) {
                $keys[] = $key;
                $ids[] = $event->id;
                $trace[] = 'consumer-break';
                break;
            }
            $trace[] = 'after-break';
        } elseif ($scenario === 'destroy') {
            $stream->rewind();
            $keys[] = $stream->key();
            $ids[] = $stream->current()->id;
            $trace[] = 'before-destroy';
        } else {
            $stream->rewind();
            if (in_array($scenario, ['send', 'caught', 'uncaught'], true)) {
                $keys[] = $stream->key();
                $ids[] = $stream->current()->id;
                $stream->send($scenario === 'send' ? 'owned-send' : null);
                if ($scenario === 'caught' || $scenario === 'uncaught') {
                    $keys[] = $stream->key();
                    $ids[] = $stream->current()->id;
                    $stream->throw($failure);
                }
            }
            while ($stream->valid()) {
                $keys[] = $stream->key();
                $ids[] = $stream->current()->id;
                $stream->next();
            }
            $return = $stream->getReturn();
        }
    } catch (Throwable $exception) {
        $caught = ['class' => $exception::class, 'same' => $exception === $failure];
        $trace[] = 'consumer-caught';
    }
    unset($stream);
    $trace[] = 'after-destroy';

    return ['telemetry' => $telemetry, 'scenario' => $scenario, 'keys' => $keys, 'ids' => $ids, 'return' => $return, 'caught' => $caught, 'trace' => $trace->getArrayCopy(), 'contextCleared' => ! Telemetry::current() instanceof TelemetryContext];
}
