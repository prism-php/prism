<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Prism\Prism\Enums\Provider;
use Symfony\Component\Process\Process;

it('transports provider tool calls and executes them in real child processes without changing their values', function (): void {
    $root = realpath(__DIR__.'/../..');
    $directory = $root.'/build/provider-tool-transport/'.bin2hex(random_bytes(12));
    foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views'] as $child) {
        mkdir($directory.'/'.$child, 0777, true);
    }
    $autoload = var_export($root.'/vendor/autoload.php', true);
    file_put_contents($directory.'/bootstrap/providers.php', '<?php return [];');
    file_put_contents($directory.'/bootstrap/app.php', '<?php require '.$autoload.'; return Illuminate\\Foundation\\Application::configure(basePath:dirname(__DIR__))->withExceptions()->withProviders([Prism\\Prism\\PrismServiceProvider::class])->create();');
    file_put_contents($directory.'/artisan', '<?php ini_set("display_errors", "stderr"); $app=require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));');
    try {
        $process = new Process([PHP_BINARY, $root.'/tests/Fixtures/Concurrency/probe.php', $directory], $directory, timeout: 180);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $expectedSurfaces = ['DeepSeek/text', 'Azure/text', 'Qwen/text', 'OpenRouter/text', 'Requesty/text',
            'DeepSeek/stream', 'Azure/stream', 'Qwen/stream', 'XAI/stream', 'Z/stream', 'Groq/stream', 'Mistral/stream',
            'XAI/text', 'Z/text', 'Groq/text', 'Mistral/text', 'OpenRouter/stream', 'Requesty/stream',
            'OpenAI/chat-text', 'OpenAI/chat-stream', 'OpenAI/responses-text', 'OpenAI/responses-reasoning', 'OpenAI/responses-stream',
            'Gemini/text-structured', 'Gemini/stream', 'Vertex/text-structured', 'Vertex/stream',
            'Anthropic/text', 'Anthropic/stream-normalizer', 'Ollama/text', 'Ollama/stream'];
        expect(array_keys($report['rows']))->toBe($expectedSurfaces);
        $providers = array_unique(array_map(fn (string $surface): string => explode('/', $surface)[0], $expectedSurfaces));
        $withoutLocalTools = array_values(array_diff(array_map(fn (Provider $provider): string => $provider->name, Provider::cases()), $providers));
        sort($withoutLocalTools);
        expect($withoutLocalTools)->toBe(['ElevenLabs', 'Perplexity', 'Replicate', 'VoyageAI']);
        foreach ($report['rows'] as $name => $row) {
            expect($row['transportError'])->toBeNull($name)->and($row['executionError'])->toBeNull($name);
            $transport = $row['transport'];
            expect($transport['pid'])->not->toBe($report['pid']);
            unset($transport['pid']);
            expect($transport)->toBe($row['expected'], $name)
                ->and($row['execution']['id'])->toBe($row['expected']['id'])
                ->and($row['execution']['args'])->toBe($row['expected']['arguments'])
                ->and($row['execution']['body']['input'])->toBe('v')
                ->and($row['execution']['body']['pid'])->not->toBe($report['pid'])
                ->and($row['execution']['success'])->toBeTrue()
                ->and($row['execution']['resultCount'])->toBe(1)
                ->and($row['normalizerOnly'])->toBe($name === 'Anthropic/stream-normalizer');
        }
    } finally {
        $target = str_replace('\\', '/', realpath($directory));
        $parent = str_replace('\\', '/', realpath($root.'/build/provider-tool-transport')).'/';
        if (! str_starts_with($target, $parent) || preg_match('/\A[0-9a-f]{24}\z/D', basename($target)) !== 1) {
            throw new RuntimeException('Refusing cleanup outside the fixture directory.');
        }
        (new Filesystem)->deleteDirectory($target);
    }
});
