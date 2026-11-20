<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Symfony\Component\Process\Process;

it('yields the first Perplexity text before the real HTTP body completes even when client streaming is disabled', function (?bool $override): void {
    $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-stream-'.bin2hex(random_bytes(8));
    mkdir($folder);
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/gated-stream-server.php', $folder]);
    $process->start();
    try {
        $deadline = microtime(true) + 10;
        while (! is_file($folder.'/ready.json') && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(is_file($folder.'/ready.json'))->toBeTrue($process->getErrorOutput());
        $ready = json_decode(file_get_contents($folder.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($ready['url'])->toMatch('/^http:\/\/127\.0\.0\.1:[1-9][0-9]+$/D');
        config()->set('prism.providers.perplexity.url', $ready['url']);
        config()->set('prism.providers.perplexity.api_key', 'synthetic-not-a-provider-key');
        Http::preventStrayRequests();
        Http::allowStrayRequests([$ready['url'].'/*']);
        $options = ['timeout' => 15];
        if ($override !== null) {
            $options['stream'] = $override;
        }
        $events = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Synthetic only.')->withClientOptions($options)->asStream();
        $text = '';
        $firstBeforeTail = null;
        foreach ($events as $event) {
            if ($event instanceof TextDeltaEvent) {
                if ($firstBeforeTail === null) {
                    $firstBeforeTail = ! file_exists($folder.'/complete');
                    file_put_contents($folder.'/ack', 'first-event-observed');
                    expect($firstBeforeTail)->toBeTrue('The HTTP transport buffered the tail before yielding the first event.');
                }
                $text .= $event->delta;
            }
        }
        expect($firstBeforeTail)->toBeTrue()
            ->and($text)->toBe('firstlast')
            ->and($process->wait())->toBe(0)
            ->and(file_get_contents($folder.'/complete'))->toBe('acknowledged-first-event');
        $request = json_decode(file_get_contents($folder.'/request.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($request['stream'])->toBeTrue();
    } finally {
        $process->stop(0.2);
        expect(dirname(realpath($folder)))->toBe(realpath(sys_get_temp_dir()));
        expect(str_starts_with(basename($folder), 'prism-stream-'))->toBeTrue();
        (new Filesystem)->deleteDirectory($folder);
    }
})->with(['default' => [null], 'caller false' => [false], 'caller true' => [true]]);

it('keeps non-stream Perplexity transport options and request bodies unchanged', function (?bool $override): void {
    config()->set('prism.providers.perplexity.api_key', 'synthetic-not-a-provider-key');
    Http::fake(function (Request $request, array $options) use ($override) {
        expect($request['stream'])->toBeFalse()
            ->and($options['stream'] ?? false)->toBe($override ?? false);

        return Http::response([
            'id' => 'synthetic', 'status' => 'completed', 'model' => 'sonar',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Synthetic only.']]]],
        ]);
    })->preventStrayRequests();
    $pending = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Synthetic only.');
    if ($override !== null) {
        $pending->withClientOptions(['stream' => $override]);
    }
    expect($pending->asText()->text)->toBe('Synthetic only.');
    Http::assertSentCount(1);
})->with(['default' => [null], 'caller false' => [false], 'caller true' => [true]]);
