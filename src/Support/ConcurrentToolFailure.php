<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use ReflectionClass;
use RuntimeException;
use Throwable;

/** @internal Native process-driver transport; never a model result or telemetry payload. */
readonly class ConcurrentToolFailure
{
    protected function __construct(
        private string|false $nativePayload,
        private string $failure,
        private float $durationMs,
    ) {}

    public static function capture(Throwable $exception, float $durationMs): self
    {
        // Match InvokeSerializedClosureCommand, including JSON parameter normalization.
        $reflection = new ReflectionClass($exception);
        $constructor = $reflection->getConstructor();
        $parameters = [];
        if ($constructor && $constructor->getDeclaringClass()->getName() === $reflection->getName()) {
            foreach ($constructor->getParameters() as $parameter) {
                $parameters[$parameter->name] = $exception->{$parameter->name} ?? null;
            }
        }

        return new self(json_encode([
            'successful' => false,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'parameters' => $parameters,
        ]), self::kind($exception::class), $durationMs);
    }

    public function validate(): void
    {
        if (array_keys(get_object_vars($this)) !== ['nativePayload', 'failure', 'durationMs']
            || ! is_finite($this->durationMs)
            || preg_match('/\Aexception:[A-Za-z0-9_]{1,64}\z/D', $this->failure) !== 1) {
            throw new RuntimeException('Invalid concurrent tool failure envelope.');
        }
        // Laravel also fails hard when the native exception cannot be JSON encoded.
        if ($this->nativePayload === false) {
            return;
        }
        $data = json_decode($this->nativePayload, true);
        if (! is_array($data) || array_keys($data) !== ['successful', 'exception', 'message', 'file', 'line', 'parameters']
            || $data['successful'] !== false || ! is_string($data['exception']) || $data['exception'] === ''
            || ! is_string($data['message']) || ! is_string($data['file']) || ! is_int($data['line'])
            || ! is_array($data['parameters']) || $this->failure !== self::kind($data['exception'])
            || (class_exists($data['exception']) && ! is_a($data['exception'], Throwable::class, true))) {
            throw new RuntimeException('Invalid concurrent tool failure envelope.');
        }
        foreach (array_keys($data['parameters']) as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid concurrent tool failure envelope.');
            }
        }
    }

    public function failure(): string
    {
        return $this->failure;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function rethrow(): never
    {
        $this->validate();
        // Native encoding failures may include worker output and vary by Laravel version.
        if ($this->nativePayload === false) {
            throw new RuntimeException('The concurrent tool failed because its exception could not be encoded.');
        }

        // Preserve ProcessDriver's parameter selection for encodable exceptions.
        /** @var array{exception: class-string<Throwable>, parameters: array<string, mixed>, message: string} $result */
        $result = json_decode($this->nativePayload, true);
        throw new $result['exception'](
            ...(array_filter($result['parameters'], fn ($parameter): bool => ! is_null($parameter)) === []
                ? [$result['message']]
                : $result['parameters'])
        );
    }

    protected static function kind(string $class): string
    {
        $short = class_basename($class);

        return 'exception:'.(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $short) === 1 ? $short : 'unknown');
    }
}
