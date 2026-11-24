<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Vertex\Vertex;
use Psr\Http\Message\RequestInterface;
use Tests\TestDoubles\LoopbackRedirectServers;

/*
 * Provider HTTP calls follow a redirect only within the request's origin
 * (scheme, host and effective port). These tests run against real loopback
 * servers: the provider endpoint answers with a redirect, and the servers it
 * points at record every request they receive.
 */

const REDIRECT_TEST_KEY = 'sk-test-redirect';

beforeEach(function (): void {
    $this->servers = LoopbackRedirectServers::start();
    $this->servers->reset();

    // Nothing in this file may leave the machine.
    Http::globalMiddleware(fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
        if (preg_match('/\A127\.\d+\.\d+\.\d+\z/', $request->getUri()->getHost()) !== 1) {
            throw new RuntimeException('Only loopback hosts may be contacted in this test.');
        }

        return $handler($request, $options);
    });

    config()->set('prism.request_timeout', 5);
});

/** @return array<string, mixed> */
function redirectProviderConfig(ProviderName $provider, string $base): array
{
    if ($provider === ProviderName::Vertex) {
        app(PrismManager::class)->extend('vertex', fn ($app, array $config): Vertex => new class('test-project', 'us-central1', REDIRECT_TEST_KEY, null, $config['url']) extends Vertex
        {
            public function __construct(string $projectId, string $region, ?string $accessToken, ?string $credentialsPath, private readonly string $loopbackBase)
            {
                parent::__construct($projectId, $region, $accessToken, $credentialsPath);
            }

            #[Override]
            protected function buildBaseUrl(): string
            {
                return $this->loopbackBase;
            }
        }, replace: true);
    }

    // The loopback servers are reached through a PER-CALL url, which is refused
    // for a private address unless the provider's config opts in. This file is
    // about redirects, not that policy, so it opts in (PerCallUrlGuardTest
    // covers the policy).
    config()->set("prism.providers.{$provider->value}.allow_private_per_call_url", true);

    return match ($provider) {
        ProviderName::Azure => ['url' => $base.'/openai/deployments/test', 'api_key' => REDIRECT_TEST_KEY, 'deployment_name' => 'test'],
        ProviderName::Vertex => ['url' => $base, 'access_token' => REDIRECT_TEST_KEY, 'project_id' => 'test-project', 'region' => 'us-central1'],
        default => ['url' => $base, 'api_key' => REDIRECT_TEST_KEY],
    };
}

function redirectModel(ProviderName $provider): string
{
    return $provider === ProviderName::Perplexity ? 'sonar' : 'test-model';
}

/** Runs the call, draining a stream, and returns what it threw. */
function redirectCapture(Closure $call): ?Throwable
{
    try {
        $result = $call();

        if ($result instanceof Generator) {
            foreach ($result as $_) {
                // drain
            }
        }
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

function expectRedirectRefused(LoopbackRedirectServers $servers, ?Throwable $caught): void
{
    $received = $servers->requests('recorder');

    expect($received)->toBe([], 'a request reached a server on another origin');
    expect(json_encode($received))->not->toContain(REDIRECT_TEST_KEY);

    expect($caught)->toBeInstanceOf(PrismException::class);

    for ($e = $caught; $e instanceof Throwable; $e = $e->getPrevious()) {
        expect($e->getMessage())->not->toContain(REDIRECT_TEST_KEY);
    }
}

/** @return array<string, array{ProviderName, string}> */
function redirectProviderCalls(): array
{
    $calls = [];

    foreach (ProviderName::cases() as $provider) {
        $kinds = match ($provider) {
            ProviderName::ElevenLabs => ['textToSpeech'],
            ProviderName::VoyageAI => ['embeddings'],
            default => ['text', 'stream'],
        };

        foreach ($kinds as $kind) {
            $calls[$provider->value.' '.$kind] = [$provider, $kind];
        }
    }

    return $calls;
}

function redirectInvoke(ProviderName $provider, string $kind, string $base, array $clientOptions = []): Closure
{
    $config = redirectProviderConfig($provider, $base);
    $model = redirectModel($provider);

    return match ($kind) {
        'text' => fn () => Prism::text()->using($provider, $model, $config)->withPrompt('hi')->withClientOptions($clientOptions)->asText(),
        'stream' => fn () => Prism::text()->using($provider, $model, $config)->withPrompt('hi')->withClientOptions($clientOptions)->asStream(),
        'embeddings' => fn () => Prism::embeddings()->using($provider, $model, $config)->fromInput('hi')->asEmbeddings(),
        'textToSpeech' => fn () => Prism::audio()->using($provider, $model, $config)->withInput('hi')->withVoice('test')->asAudio(),
    };
}

function redirectProviderClient(ProviderName $provider, string $base): PendingRequest
{
    $instance = app(PrismManager::class)->resolve($provider, redirectProviderConfig($provider, $base));

    expect($instance)->toBeInstanceOf(Provider::class);

    $client = (new ReflectionMethod($instance, 'client'))->invoke($instance);

    expect($client)->toBeInstanceOf(PendingRequest::class);

    return $client;
}

it('does not follow a redirect to another host for :dataset', function (ProviderName $provider, string $kind): void {
    $caught = redirectCapture(redirectInvoke($provider, $kind, $this->servers->redirectingBase(307, 'host')));

    expectRedirectRefused($this->servers, $caught);
})->with(redirectProviderCalls());

it('does not follow a :dataset redirect to another host or port', function (int $status, string $target, string $kind): void {
    $caught = redirectCapture(redirectInvoke(ProviderName::OpenAI, $kind, $this->servers->redirectingBase($status, $target)));

    expectRedirectRefused($this->servers, $caught);
})->with([301, 302, 303, 307, 308])->with(['host', 'port'])->with(['text', 'stream']);

it('does not follow a redirect to another origin when the caller enables redirects', function (string $kind): void {
    $caught = redirectCapture(redirectInvoke(
        ProviderName::Anthropic,
        $kind,
        $this->servers->redirectingBase(302, 'host'),
        ['allow_redirects' => ['max' => 5, 'strict' => true]],
    ));

    expectRedirectRefused($this->servers, $caught);
})->with(['text', 'stream']);

it('does not follow a redirect to another origin from the client of :dataset', function (ProviderName $provider): void {
    $client = redirectProviderClient($provider, $this->servers->redirectingBase(302, 'port'));

    $caught = redirectCapture(fn () => $client->get('ping'));

    expectRedirectRefused($this->servers, $caught);
    expect($caught)->toBeInstanceOf(PrismUrlRefused::class)
        ->and($caught->code())->toBe('redirect_refused');
})->with(ProviderName::cases());

it('does not follow a redirect to another origin when listing :dataset', function (ProviderName $provider, string $kind): void {
    $config = redirectProviderConfig($provider, $this->servers->redirectingBase(302, 'host'));

    $caught = redirectCapture(match ($kind) {
        'files' => fn () => Prism::files()->using($provider, '', $config)->list(),
        'batches' => fn () => Prism::batch()->using($provider, '', $config)->list(),
    });

    expectRedirectRefused($this->servers, $caught);
})->with([
    'openai files' => [ProviderName::OpenAI, 'files'],
    'openai batches' => [ProviderName::OpenAI, 'batches'],
    'anthropic files' => [ProviderName::Anthropic, 'files'],
    'anthropic batches' => [ProviderName::Anthropic, 'batches'],
]);

it('does not follow a redirect that changes the scheme', function (): void {
    $client = redirectProviderClient(ProviderName::OpenAI, $this->servers->redirectingBase(302, 'scheme'));

    $caught = redirectCapture(fn () => $client->get('ping'));

    expect($caught)->toBeInstanceOf(PrismUrlRefused::class)
        ->and($this->servers->requests('endpoint'))->toHaveCount(1);
    expectRedirectRefused($this->servers, $caught);
});

it('follows a :dataset redirect within the same origin', function (int $status): void {
    $client = redirectProviderClient(ProviderName::OpenAI, $this->servers->redirectingBase($status, 'same'));

    $response = $client->post('ping', ['a' => 1]);

    $expectedMethod = in_array($status, [307, 308], true) ? 'POST' : 'GET';

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('method'))->toBe($expectedMethod)
        ->and($this->servers->requests('endpoint'))->toHaveCount(2)
        ->and($this->servers->requests('recorder'))->toBe([]);
})->with([301, 302, 303, 307, 308]);
