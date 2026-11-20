<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRunException;
use Prism\Prism\Facades\Prism;

// Controlled fault injections into the existing compatibility fixture, NOT live
// recordings. Live acceptance is separately measured by the operator in the Lab.
/** @return array<string, mixed> */
function perplexity103Body(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/perplexity/agent-run-incomplete-1.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('includes the provider incomplete reason and transport request id while keeping partial content private', function (): void {
    $body = perplexity103Body();
    Http::fake(['*' => Http::response($body, 200, ['X-Request-ID' => 'req_transport'])])->preventStrayRequests();
    try {
        Prism::text()->using(Provider::Perplexity, 'sonar-deep-research')->withPrompt('Research')->asText();
        test()->fail('Incomplete is not success.');
    } catch (PrismRunException $e) {
        expect($e->getMessage())->toContain('max_output_tokens', 'req_transport')
            ->and($e->providerRequestId())->toBe('req_transport')
            ->and($e->partialText())->toBe('Private partial finding')
            ->and($e->getMessage())->not->toContain('Private partial finding')
            ->and(json_encode($e))->not->toContain('Private partial finding');
    }
});

it('preserves failed provider message and code without returning an empty provider_error', function (): void {
    $body = perplexity103Body();
    $body['status'] = 'failed';
    $body['error'] = ['type' => 'provider_error', 'code' => 'upstream_failed', 'message' => 'The upstream model stopped.'];
    Http::fake(['*' => Http::response($body, 200, ['X-Request-ID' => 'req_failed'])])->preventStrayRequests();
    try {
        Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withPrompt('Research')->asText();
        test()->fail('Failed is not success.');
    } catch (PrismRunException $e) {
        expect($e->getMessage())->toContain('upstream_failed', 'The upstream model stopped.', 'req_failed')
            ->and($e->providerCode())->toBe('upstream_failed')
            ->and($e->providerError())->toBe($body['error']);
    }
});

it('states when a provider sent no message and retains an available request id', function (): void {
    Http::fake(['*' => Http::response(['status' => 'failed', 'error' => ['type' => 'provider_error', 'message' => '']], 200, ['X-Request-ID' => 'req_empty'])])->preventStrayRequests();
    expect(fn () => Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText())
        ->toThrow(PrismRunException::class, 'provider returned no error message');
});

it('does not let Laravel truncate a provider validation error', function (): void {
    $message = 'validation failed: '.str_repeat('field detail; ', 100).'FINAL_PROVIDER_DETAIL';
    Http::fake(['*' => Http::response(['error' => ['message' => $message, 'code' => 'invalid_request', 'type' => 'invalid_request']], 400, ['X-Request-ID' => 'req_400'])])->preventStrayRequests();
    expect(fn () => Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withPrompt('Research')->asText())
        ->toThrow(PrismException::class, 'FINAL_PROVIDER_DETAIL');
});

it('marks the explicit diagnostic bound rather than silently clipping an error', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => str_repeat('x', 9000), 'code' => 'provider_error']], 400, ['X-Request-ID' => 'req_bound'])])->preventStrayRequests();
    expect(fn () => Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText())
        ->toThrow(PrismException::class, 'truncated at 8192 bytes');
});

it('uses the documented effort spelling for legacy sonar and preset inputs', function (string $model, array $options, string $effort): void {
    Http::fake(['*' => Http::response(['status' => 'completed', 'output' => []])])->preventStrayRequests();
    Prism::text()->using(Provider::Perplexity, $model)->withProviderOptions($options)->withPrompt('Research')->asText();
    Http::assertSent(fn ($r): bool => ($r->data()['effort'] ?? null) === $effort && ! array_key_exists('preset', $r->data()) && ! array_key_exists('model', $r->data()));
})->with([['sonar-deep-research', [], 'medium'], ['sonar-pro', [], 'low'], ['sonar', ['preset' => 'high'], 'high']]);

it('builds a nonempty documented models list from a direct model', function (): void {
    Http::fake(['*' => Http::response(['status' => 'completed', 'output' => []])])->preventStrayRequests();
    Prism::text()->using(Provider::Perplexity, 'perplexity/sonar')->withPrompt('Research')->asText();
    Http::assertSent(fn ($r): bool => ($r->data()['models'] ?? null) === ['perplexity/sonar']);
});

it('refuses malformed or empty model chains before sending', function (mixed $models): void {
    Http::preventStrayRequests();
    expect(fn () => Prism::text()->using(Provider::Perplexity, 'sonar')->withProviderOptions(['models' => $models])->withPrompt('Research')->asText())
        ->toThrow(PrismException::class, '[models]');
    Http::assertNothingSent();
})->with(['empty' => [[]], 'blank' => [['']], 'retired' => [['sonar-pro']], 'unqualified' => [['model']], 'too many' => [['a/b', 'a/c', 'a/d', 'a/e', 'a/f', 'a/g']], 'scalar' => ['not-an-array']]);

it('uses a configurable deep timeout while preserving an explicit client timeout', function (array $options, float $expected): void {
    config()->set('prism.providers.perplexity.deep_research_timeout', 600);
    $seen = null;
    Http::fake(function ($r, array $o) use (&$seen) {
        $seen = $o['timeout'];

        return Http::response(['status' => 'completed', 'output' => []]);
    })->preventStrayRequests();
    Prism::text()->using(Provider::Perplexity, 'sonar-deep-research')->withClientOptions($options)
        ->withPrompt('Research')->asText();
    // Laravel's recorded options are the transport configuration, not timings
    // guessed from a fake response.
    expect((float) $seen)->toBe($expected);
})->with([[[], 600.0], [['timeout' => 123], 123.0]]);

it('parses the published response example without inventing absent usage or request ids', function (): void {
    $path = __DIR__.'/../../Fixtures/perplexity/documented-agent-response.json';
    $provenance = json_decode(file_get_contents($path.'.provenance.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe($provenance['fixtureSha256']);
    Http::fake(['*' => Http::response(file_get_contents($path))])->preventStrayRequests();
    $response = Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withPrompt('Research')->asText();
    expect($response->meta->id)->toBe('resp_18690c07-7c56-48fa-85a3-fd22e34df694')
        ->and($response->additionalContent['search_results'])->toHaveCount(1)
        ->and($response->text)->toContain('greater autonomy')
        ->and($response->additionalContent)->not->toHaveKey('provider_request_id');
});

it('refuses documented invalid step and output bounds before sending', function (string $option, mixed $value): void {
    Http::preventStrayRequests();
    $pending = Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withPrompt('Research');
    if ($option === 'max_output_tokens') {
        $pending->withMaxTokens($value);
    } else {
        $pending->withProviderOptions([$option => $value]);
    }
    expect(fn () => $pending->asText())->toThrow(PrismException::class, '['.$option.']');
    Http::assertNothingSent();
})->with([['max_steps', 0], ['max_steps', 501], ['max_steps', 1.5], ['max_steps', '3'], ['max_output_tokens', 0], ['metadata', ['run' => 'local']]]);

it('requires an output bound for documented Anthropic fallback models', function (): void {
    Http::preventStrayRequests();
    expect(fn () => Prism::text()->using(Provider::Perplexity, 'anthropic/claude-sonnet-5-5')->withPrompt('Research')->asText())
        ->toThrow(PrismException::class, '[max_output_tokens]');
    Http::assertNothingSent();
});

it('keeps current documented recency and date composition intact', function (): void {
    Http::fake(['*' => Http::response(['status' => 'completed', 'output' => []])])->preventStrayRequests();
    Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withProviderOptions([
        'search_recency_filter' => 'month', 'search_after_date_filter' => '01/01/2026',
    ])->withPrompt('Research')->asText();
    Http::assertSent(fn ($r): bool => $r->data()['tools'][0]['filters'] === [
        'search_recency_filter' => 'month', 'search_after_date_filter' => '01/01/2026',
    ]);
});

it('retains transport request identity on an incomplete stream', function (): void {
    $body = perplexity103Body();
    Http::fake(['*' => Http::response('data: '.json_encode(['type' => 'response.incomplete', 'response' => $body])."\n\n", 200, ['Content-Type' => 'text/event-stream', 'X-Request-ID' => 'req_stream'])])->preventStrayRequests();
    try {
        iterator_to_array(Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asStream());
        test()->fail('Incomplete stream cannot succeed.');
    } catch (PrismRunException $e) {
        expect($e->providerRequestId())->toBe('req_stream')
            ->and($e->incompleteReason())->toBe('max_output_tokens')
            ->and($e->partialText())->toBe('Private partial finding');
    }
});
