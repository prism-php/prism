<?php

declare(strict_types=1);

namespace Tests\TestDoubles;

use RuntimeException;

/**
 * Three `php -S` servers on loopback addresses, for redirect tests.
 *
 * - the endpoint, on 127.0.0.1:A, which answers /r/{status}/{target}/... with
 *   a redirect to one of the targets below;
 * - a recorder on another host, 127.0.0.2:B;
 * - a recorder on the same host and another port, 127.0.0.1:C.
 *
 * Every request any of them receives is appended to one log file.
 */
final class LoopbackRedirectServers
{
    private static ?self $instance = null;

    /** @var array<int, resource> */
    private array $processes = [];

    private function __construct(
        public readonly string $log,
        public readonly int $endpointPort,
        public readonly int $otherHostPort,
        public readonly int $otherPortPort,
    ) {}

    public static function start(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $log = tempnam(sys_get_temp_dir(), 'prism-redirect-');

        if ($log === false) {
            throw new RuntimeException('Could not create the request log.');
        }

        $servers = new self($log, self::freePort('127.0.0.1'), self::freePort('127.0.0.2'), self::freePort('127.0.0.1'));

        $servers->spawn('127.0.0.2', $servers->otherHostPort, ['ROLE' => 'recorder']);
        $servers->spawn('127.0.0.1', $servers->otherPortPort, ['ROLE' => 'recorder']);
        $servers->spawn('127.0.0.1', $servers->endpointPort, [
            'ROLE' => 'endpoint',
            'TARGET_HOST' => $servers->otherHostOrigin(),
            'TARGET_PORT' => $servers->otherPortOrigin(),
            'TARGET_SAME' => $servers->endpointOrigin(),
            'TARGET_SCHEME' => 'https://127.0.0.1:'.$servers->endpointPort,
        ]);

        register_shutdown_function($servers->stop(...));

        return self::$instance = $servers;
    }

    public function endpointOrigin(): string
    {
        return 'http://127.0.0.1:'.$this->endpointPort;
    }

    public function otherHostOrigin(): string
    {
        return 'http://127.0.0.2:'.$this->otherHostPort;
    }

    public function otherPortOrigin(): string
    {
        return 'http://127.0.0.1:'.$this->otherPortPort;
    }

    /** A base URL on the endpoint that redirects with $status to $target. */
    public function redirectingBase(int $status, string $target): string
    {
        return sprintf('%s/r/%d/%s', $this->endpointOrigin(), $status, $target);
    }

    public function reset(): void
    {
        file_put_contents($this->log, '');
    }

    /** @return array<int, array{role: string, host: string, method: string, uri: string, headers: array<string, string>, body: string}> */
    public function requests(?string $role = null): array
    {
        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($this->log)));
        $requests = array_values(array_map(fn (string $line): array => json_decode($line, true), $lines));

        return $role === null
            ? $requests
            : array_values(array_filter($requests, fn (array $request): bool => $request['role'] === $role));
    }

    public function stop(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }

        $this->processes = [];

        if (is_file($this->log)) {
            @unlink($this->log);
        }

        self::$instance = null;
    }

    /** @param  array<string, string>  $env */
    private function spawn(string $host, int $port, array $env): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $process = proc_open(
            [PHP_BINARY, '-S', $host.':'.$port, __DIR__.'/../Fixtures/redirect-origin/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            array_merge(getenv(), $env, ['LOG' => $this->log]),
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Could not start a server on {$host}:{$port}.");
        }

        $this->processes[] = $process;

        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException("The server on {$host}:{$port} did not start.");
    }

    private static function freePort(string $host): int
    {
        $server = stream_socket_server("tcp://{$host}:0", $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Could not reserve a port on {$host}: {$errstr}");
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
