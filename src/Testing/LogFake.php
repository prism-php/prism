<?php

declare(strict_types=1);

namespace Prism\Prism\Testing;

use Illuminate\Log\LogManager;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;

class LogFake implements LoggerInterface
{
    /** @var array<int, array{level: mixed, message: mixed, context: array<string, mixed>, timestamp: float}> */
    protected array $logs = [];

    public function emergency(mixed $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(mixed $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(mixed $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(mixed $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(mixed $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(mixed $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(mixed $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(mixed $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
        ];
    }

    /** @return Collection<int, array{level: mixed, message: mixed, context: array<string, mixed>, timestamp: float}> */
    public function logged(?string $level = null, ?string $message = null): Collection
    {
        return collect($this->logs)->filter(function (array $log) use ($level, $message): bool {
            if ($level && $log['level'] !== $level) {
                return false;
            }

            return ! ($message && ! str_contains((string) $log['message'], $message));
        });
    }

    public function hasLogged(?string $level = null, ?string $message = null): bool
    {
        return $this->logged($level, $message)->isNotEmpty();
    }

    public function assertLogged(?string $level = null, ?string $message = null): void
    {
        Assert::assertTrue(
            $this->hasLogged($level, $message),
            "Expected log entry not found. Level: {$level}, Message: {$message}"
        );
    }

    public function assertNotLogged(?string $level = null, ?string $message = null): void
    {
        Assert::assertFalse(
            $this->hasLogged($level, $message),
            "Unexpected log entry found. Level: {$level}, Message: {$message}"
        );
    }

    public function assertLoggedCount(int $count, ?string $level = null, ?string $message = null): void
    {
        $actualCount = $this->logged($level, $message)->count();

        Assert::assertEquals(
            $count,
            $actualCount,
            "Expected {$count} log entries but found {$actualCount}"
        );
    }

    public function clear(): void
    {
        $this->logs = [];
    }

    /** @return array<int, array{level: mixed, message: mixed, context: array<string, mixed>, timestamp: float}> */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function channel(?string $channel = null): self
    {
        return $this;
    }

    public static function swap(?string $channel = null): self
    {
        $fake = new self;

        if ($channel) {
            app(LogManager::class)->extend($channel, fn (): LogFake => $fake);
        } else {
            app()->instance('log', $fake);
        }

        return $fake;
    }
}
