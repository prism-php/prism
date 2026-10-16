<?php

declare(strict_types=1);

use Prism\Prism\Providers\Gemini\Concerns\MapsUsage as MapsGeminiUsage;
use Prism\Prism\Providers\Groq\Concerns\MapsUsage as MapsGroqUsage;
use Prism\Prism\Providers\Mistral\Concerns\MapsUsage as MapsMistralUsage;
use Prism\Prism\Providers\Ollama\Concerns\MapsUsage as MapsOllamaUsage;
use Prism\Prism\ValueObjects\Usage;

/**
 * @return array<string, array{0: string, 1: array<string, mixed>}>
 */
function recordedUsageResponses(): array
{
    $responses = [];

    foreach (['gemini' => 'usageMetadata', 'mistral' => 'usage', 'groq' => 'usage', 'ollama' => 'prompt_eval_count'] as $provider => $usageKey) {
        foreach (glob(__DIR__."/../../../Fixtures/{$provider}/*.json") ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (is_array($data) && array_key_exists($usageKey, $data)) {
                $responses[$provider.'/'.basename($file)] = [$provider, $data];
            }
        }
    }

    return $responses;
}

function mapRecordedUsage(string $provider, array $data): Usage
{
    $mapper = match ($provider) {
        'gemini' => new class
        {
            use MapsGeminiUsage { mapUsage as public; }
        },
        'mistral' => new class
        {
            use MapsMistralUsage { mapUsage as public; }
        },
        'groq' => new class
        {
            use MapsGroqUsage { mapUsage as public; }
        },
        'ollama' => new class
        {
            use MapsOllamaUsage { mapUsage as public; }
        },
    };

    return $mapper->mapUsage($provider === 'groq' ? $data['usage'] : $data);
}

it('maps recorded responses to non-negative buckets with thoughts inside the completion', function (string $provider, array $data): void {
    $usage = mapRecordedUsage($provider, $data);

    expect($usage->promptTokens)->toBeGreaterThanOrEqual(0)
        ->and($usage->completionTokens)->toBeGreaterThanOrEqual(0)
        ->and($usage->cacheReadInputTokens ?? 0)->toBeGreaterThanOrEqual(0)
        ->and($usage->thoughtTokens ?? 0)->toBeGreaterThanOrEqual(0)
        ->and($usage->thoughtTokens ?? 0)->toBeLessThanOrEqual($usage->completionTokens);
})->with(recordedUsageResponses());
