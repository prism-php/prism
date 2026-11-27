<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', 'owned-replicate-key');
    Http::preventStrayRequests();
});

function replicateOutputBytes(string $capability): ?string
{
    if ($capability === 'audio') {
        return Prism::audio()
            ->using('replicate', 'owned/tts')
            ->withInput('Hello')
            ->withVoice('owned-voice')
            ->withClientOptions(['headers' => ['X-Owned-Api-Secret' => 'api-only', 'Cookie' => 'owned-api-cookie=secret'], 'allow_redirects' => true])
            ->asAudio()->audio->base64;
    }

    return Prism::image()
        ->using('replicate', 'owned/image')
        ->withPrompt('An otter')
        ->withClientOptions(['headers' => ['X-Owned-Api-Secret' => 'api-only', 'Cookie' => 'owned-api-cookie=secret'], 'allow_redirects' => true])
        ->generate()->firstImage()->base64;
}

function fakeReplicateOutput(string $url, int $status = 200, array $headers = []): void
{
    Http::fake([
        'https://api.replicate.com/v1/predictions' => Http::response([
            'id' => 'owned-prediction', 'status' => 'succeeded', 'output' => $url,
        ], 201),
        '*' => Http::response('owned-output-bytes', $status, $headers),
    ]);
}

function fakeReplicateOutputTransport(array $responses, array &$history): void
{
    $transport = Middleware::history($history)(new MockHandler($responses));
    Http::swap(new class($transport) extends Factory
    {
        public function __construct(private readonly \Closure $transport) {}

        protected function newPendingRequest(): PendingRequest
        {
            return parent::newPendingRequest()->setHandler($this->transport);
        }
    });
}

it('keeps API credentials on predictions and sends no authentication to output hosts', function (string $capability): void {
    // Exercise the shipped default allowlist, including a subdomain.
    $url = 'https://cdn.replicate.delivery/owned-output';
    fakeReplicateOutput($url);

    expect(base64_decode((string) replicateOutputBytes($capability)))->toBe('owned-output-bytes');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.replicate.com/v1/predictions'
        && $request->hasHeader('Authorization', 'Bearer owned-replicate-key')
        && $request->hasHeader('X-Owned-Api-Secret', 'api-only'));
    Http::assertSent(fn (Request $request): bool => $request->url() === $url
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('X-Owned-Api-Secret')
        && ! $request->hasHeader('Cookie'));
    Http::assertSentCount(2);
})->with(['audio', 'images']);

it('refuses unsafe output URLs before any download request', function (string $capability, string $url): void {
    fakeReplicateOutput($url);

    try {
        if ($capability === 'audio') {
            expect(fn (): ?string => replicateOutputBytes($capability))->toThrow(PrismException::class);
        } else {
            expect(replicateOutputBytes($capability))->toBeNull();
        }
    } finally {
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $url);
    }
})->with(['audio', 'images'])->with([
    'loopback' => 'http://127.0.0.1/owned-audio.mp3',
    'metadata' => 'https://169.254.169.254/latest/meta-data',
    'other host' => 'https://owned-output.example/clip',
    'suffix confusion' => 'https://replicate.delivery.owned-output.example/clip',
    'prefix confusion' => 'https://owned-replicate.delivery/clip',
    'http' => 'http://replicate.delivery/clip',
    'relative' => '/owned-clip',
    'file' => 'file:///owned-clip',
    'userinfo' => 'https://owned:secret@replicate.delivery/clip',
    'alternate port' => 'https://replicate.delivery:8443/clip',
]);

it('honors the same custom media host policy for audio and images', function (string $capability): void {
    config()->set('prism.providers.replicate.download_hosts', ['owned-output.example']);
    fakeReplicateOutput('https://cdn.owned-output.example/clip');

    expect(base64_decode((string) replicateOutputBytes($capability)))->toBe('owned-output-bytes');
    Http::assertSentCount(2);
})->with(['audio', 'images']);

it('fails closed when the media host policy is empty', function (string $capability): void {
    config()->set('prism.providers.replicate.download_hosts', []);
    fakeReplicateOutput('https://replicate.delivery/clip');

    try {
        if ($capability === 'audio') {
            expect(fn (): ?string => replicateOutputBytes($capability))->toThrow(PrismException::class);
        } else {
            expect(replicateOutputBytes($capability))->toBeNull();
        }
    } finally {
        Http::assertSentCount(1);
    }
})->with(['audio', 'images']);

it('never returns redirect or error bodies as generated media', function (string $capability, int $status): void {
    fakeReplicateOutput('https://replicate.delivery/clip', $status, ['Location' => 'http://127.0.0.1/owned-redirect']);

    if ($capability === 'audio') {
        expect(fn (): ?string => replicateOutputBytes($capability))->toThrow(PrismException::class);
    } else {
        expect(replicateOutputBytes($capability))->toBeNull();
    }

    Http::assertSentCount(2);
})->with(['audio', 'images'])->with([302, 404, 500]);

it('omits signed URLs and transport details from audio download exceptions', function (): void {
    $url = 'https://replicate.delivery/owned?signature=owned-signed-secret';
    Http::fake([
        'https://api.replicate.com/v1/predictions' => Http::response([
            'id' => 'owned-prediction', 'status' => 'succeeded', 'output' => $url,
        ], 201),
        $url => fn () => throw new ConnectionException('Transport failed at '.$url),
    ]);

    $failure = null;

    try {
        replicateOutputBytes('audio');
    } catch (PrismException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(PrismException::class)
        ->and($failure?->getMessage())->toBe('Replicate output download failed.')
        ->and($failure?->getPrevious())->toBeNull();
});

it('disables redirects on the real HTTP handler stack for media', function (string $capability): void {
    // Http::fake does not follow redirects. An owned Guzzle transport exercises
    // the actual redirect middleware without permitting any network access.
    $history = [];
    fakeReplicateOutputTransport([
        new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'owned-prediction', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/clip',
        ], JSON_THROW_ON_ERROR)),
        new Response(302, ['Location' => 'http://127.0.0.1/owned-redirect']),
        new Response(200, [], 'must-not-be-read'),
    ], $history);

    if ($capability === 'audio') {
        expect(fn (): ?string => replicateOutputBytes($capability))->toThrow(PrismException::class);
    } else {
        expect(replicateOutputBytes($capability))->toBeNull();
    }

    expect($history)->toHaveCount(2)
        ->and($history[1]['request']->getUri()->getHost())->toBe('replicate.delivery')
        ->and($history[1]['request']->hasHeader('Authorization'))->toBeFalse();
})->with(['audio', 'images']);

it('bounds media reads even without an honest Content-Length and closes the stream', function (string $capability, bool $declaredOversize): void {
    $read = 0;
    $stream = new PumpStream(function (int $length) use (&$read): string|false {
        $length = min($length, 25 * 1024 * 1024 + 4096 - $read);

        if ($length <= 0) {
            return false;
        }

        $read += $length;

        return str_repeat('x', $length);
    });
    $history = [];
    fakeReplicateOutputTransport([
        new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'owned-prediction', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/clip',
        ], JSON_THROW_ON_ERROR)),
        new Response(200, $declaredOversize ? ['Content-Length' => (string) (25 * 1024 * 1024 + 1)] : [], $stream),
    ], $history);

    if ($capability === 'audio') {
        expect(fn (): ?string => replicateOutputBytes($capability))->toThrow(PrismException::class);
    } else {
        expect(replicateOutputBytes($capability))->toBeNull();
    }

    expect($read)->toBe($declaredOversize ? 0 : 25 * 1024 * 1024 + 1)
        ->and($stream->eof())->toBeTrue()
        ->and($stream->tell())->toBe(0);
})->with(['audio', 'images'])->with([true, false]);

it('does not follow SSE redirects through the HTTP transport', function (string $host): void {
    $history = [];
    fakeReplicateOutputTransport([
        new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'owned-prediction', 'status' => 'processing',
            'urls' => ['stream' => 'https://'.$host.'/owned-stream'],
        ], JSON_THROW_ON_ERROR)),
        new Response(302, ['Location' => 'https://owned-output.example/must-not-receive-key']),
        new Response(200, [], "event: done\ndata: {}\n\n"),
    ], $history);

    expect(fn (): array => iterator_to_array(Prism::text()->using('replicate', 'owned/text')->withPrompt('Hello')->asStream()))
        ->toThrow(PrismException::class);
    expect($history)->toHaveCount(2)
        ->and($history[1]['request']->hasHeader('Authorization'))->toBe($host === 'streaming.api.replicate.com');
})->with(['stream.replicate.com', 'streaming.api.replicate.com']);

it('restricts authenticated SSE connections to documented Replicate stream hosts', function (string $url): void {
    Http::fake([
        'https://api.replicate.com/v1/predictions' => Http::response([
            'id' => 'owned-prediction', 'status' => 'processing', 'urls' => ['stream' => $url],
        ], 201),
        '*' => Http::response("event: done\ndata: {}\n\n", 200),
    ]);

    try {
        expect(fn (): array => iterator_to_array(Prism::text()->using('replicate', 'owned/text')->withPrompt('Hello')->asStream()))
            ->toThrow(PrismException::class);
    } finally {
        Http::assertSentCount(1);
    }
})->with([
    'https://owned-output.example/stream',
    'http://stream.replicate.com/stream',
    'https://stream.replicate.com.owned-output.example/stream',
    'https://owned.stream.replicate.com/stream',
    'https://owned:secret@stream.replicate.com/stream',
    'https://stream.replicate.com:8443/stream',
]);

it('continues to consume native SSE on documented hosts', function (string $host): void {
    $url = 'https://'.$host.'/owned-stream';
    Http::fake([
        'https://api.replicate.com/v1/predictions' => Http::response([
            'id' => 'owned-prediction', 'status' => 'processing', 'urls' => ['stream' => $url],
        ], 201),
        $url => Http::response("event: output\ndata: Hello\n\nevent: done\ndata: {}\n\n", 200),
    ]);

    $text = '';
    foreach (Prism::text()->using('replicate', 'owned/text')->withPrompt('Hello')
        ->withClientOptions(['headers' => ['X-Owned-Api-Secret' => 'api-only'], 'allow_redirects' => true])
        ->asStream() as $event) {
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($text)->toBe('Hello');
    Http::assertSent(fn (Request $request): bool => $request->url() === $url
        && ($host === 'streaming.api.replicate.com'
            ? $request->hasHeader('Authorization', 'Bearer owned-replicate-key') && $request->hasHeader('X-Owned-Api-Secret', 'api-only')
            : ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Owned-Api-Secret')));
    Http::assertSentCount(2);
})->with(['stream.replicate.com', 'streaming.api.replicate.com']);
