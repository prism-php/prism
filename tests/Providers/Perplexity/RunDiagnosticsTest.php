<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRunException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

it('retains typed unsuccessful run diagnostics without exposing partial text in the message', function (string $status, bool $structured): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    Event::fake([GenerationCompleted::class]);
    $body = json_decode(file_get_contents(__DIR__.'/../../Fixtures/perplexity/agent-run-incomplete-1.json'), true, flags: JSON_THROW_ON_ERROR);
    $body['status'] = $status;
    Http::fake(['api.perplexity.ai/*' => Http::response($body, 200)])->preventStrayRequests();

    try {
        if ($structured) {
            Prism::structured()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')
                ->withSchema(new ObjectSchema('result', 'Result', [new StringSchema('text', 'Text')], ['text']))
                ->asStructured();
        } else {
            Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText();
        }

        test()->fail('An unsuccessful run must still throw.');
    } catch (PrismException $exception) {
        expect($exception)->toBeInstanceOf(PrismRunException::class);
        expect($exception->code())->toBe('run_'.$status)
            ->and($exception->status())->toBe($status)
            ->and($exception->getCode())->toBe(200)
            ->and($exception->httpStatus)->toBe(200)
            ->and($exception->runId())->toBe('resp_incomplete')
            ->and($exception->incompleteReason())->toBe('max_output_tokens')
            ->and($exception->incompleteDetails())->toBe($body['incomplete_details'])
            ->and($exception->output())->toBe($body['output'])
            ->and($exception->citations())->toBe([
                ['type' => 'url_citation', 'url' => 'https://example.test/source', 'title' => 'Source'],
            ])
            ->and($exception->output()[0]['content'][0]['annotations'])->toBe([
                ['type' => 'url_citation', 'url' => 'https://example.test/source', 'title' => 'Source'],
            ])
            ->and($exception->output()[1])->toBe([
                'type' => 'search_results',
                'results' => [['url' => 'https://example.test/source', 'title' => 'Source', 'snippet' => 'Private source excerpt']],
            ])
            ->and($exception->usage()->promptTokens)->toBe(12)
            ->and($exception->usage()->completionTokens)->toBe(2048)
            ->and($exception->usage()->cost)->toBe(0.01)
            ->and($exception->usageDetails())->toBe($body['usage'])
            ->and($exception->getMessage())->toBe('Perplexity Error [200]: run_'.$status.' - no error detail was returned')
            ->and((string) $exception)->not->toContain('Private partial finding')
            ->and(json_encode($exception))->not->toContain('Private partial finding')
            ->and($exception->getMessage())->not->toContain('Private source excerpt')
            ->and(json_encode($exception))->not->toContain('Private source excerpt')
            ->and($exception->responseBody)->toBeNull();
        Event::assertNotDispatched(GenerationCompleted::class);
    }
})->with(['incomplete', 'failed', 'cancelled'])->with([false, true]);

it('still returns a completed run normally', function (): void {
    $body = json_decode(file_get_contents(__DIR__.'/../../Fixtures/perplexity/agent-run-incomplete-1.json'), true, flags: JSON_THROW_ON_ERROR);
    $body['status'] = 'completed';
    $body['incomplete_details'] = null;
    Http::fake(['api.perplexity.ai/*' => Http::response($body, 200)])->preventStrayRequests();

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText();

    expect($response->text)->toBe('Private partial finding')
        ->and($response->meta->id)->toBe('resp_incomplete')
        ->and($response->usage->promptTokens)->toBe(12)
        ->and($response->usage->completionTokens)->toBe(2048);
});

it('preserves failed and cancelled fixture diagnostics and existing messages', function (string $status): void {
    $body = json_decode(file_get_contents(__DIR__.'/../../Fixtures/perplexity/agent-run-'.$status.'-1.json'), true, flags: JSON_THROW_ON_ERROR);
    Http::fake(['api.perplexity.ai/*' => Http::response($body, 200)])->preventStrayRequests();

    try {
        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText();
        test()->fail('An unsuccessful run must throw.');
    } catch (PrismRunException $exception) {
        expect($exception->code())->toBe('run_'.$status)
            ->and($exception->runId())->toBe($body['id'])
            ->and($exception->incompleteReason())->toBeNull()
            ->and($exception->output())->toBe([])
            ->and($exception->citations())->toBe([])
            ->and($exception->usage()->promptTokens)->toBe(8)
            ->and($exception->usage()->completionTokens)->toBe(0)
            ->and($exception->usageDetails())->toBe($body['usage'])
            ->and($exception->getMessage())->toBe('Perplexity Error [200]: run_'.$status.' - '.$body['error']['message']);
    }
})->with(['failed', 'cancelled']);

it('tolerates null or malformed optional diagnostics without losing the run code', function (mixed $value): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'status' => 'incomplete', 'id' => 123, 'incomplete_details' => $value, 'output' => $value, 'usage' => $value,
    ], 200)])->preventStrayRequests();

    try {
        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText();
        test()->fail('An incomplete run must throw.');
    } catch (PrismRunException $exception) {
        expect($exception->code())->toBe('run_incomplete')
            ->and($exception->runId())->toBeNull()
            ->and($exception->incompleteReason())->toBeNull()
            ->and($exception->output())->toBe([])
            ->and($exception->usage())->toBeNull()
            ->and($exception->usageDetails())->toBe([]);
    }
})->with([null, 'invalid']);

it('does not invent diagnostics when the provider omits them', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response(['status' => 'failed'], 200)])->preventStrayRequests();

    try {
        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText();
        test()->fail('A failed run must throw.');
    } catch (PrismException $exception) {
        expect($exception)->toBeInstanceOf(PrismRunException::class);
        expect($exception->runId())->toBeNull()
            ->and($exception->incompleteReason())->toBeNull()
            ->and($exception->incompleteDetails())->toBe([])
            ->and($exception->output())->toBe([])
            ->and($exception->usage())->toBeNull()
            ->and($exception->usageDetails())->toBe([]);
    }
});

it('keeps a connection failure distinct from a provider run failure', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::failedConnection()])->preventStrayRequests();

    expect(fn () => Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText())
        ->toThrow(ConnectionException::class);
});

it('preserves reported zero usage and cost rather than treating them as absent', function (): void {
    Http::fake(['api.perplexity.ai/*' => Http::response([
        'status' => 'cancelled',
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => ['total_cost' => 0]],
    ], 200)])->preventStrayRequests();

    try {
        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Research')->asText();
        test()->fail('A cancelled run must throw.');
    } catch (PrismRunException $exception) {
        expect($exception->usage()->promptTokens)->toBe(0)
            ->and($exception->usage()->completionTokens)->toBe(0)
            ->and($exception->usage()->cost)->toBe(0.0);
    }
});
