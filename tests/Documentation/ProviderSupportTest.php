<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Support\CapabilityProbe;

/** @return array<string, array<string, string>> */
function documentedProviderRows(string $component): array
{
    $source = file_get_contents(__DIR__.'/../../docs/components/'.$component.'.vue');
    preg_match_all('/\{\s+name: "([^"]+)",([^{}]+)\}/', $source, $matches, PREG_SET_ORDER);

    $rows = [];
    foreach ($matches as $match) {
        preg_match_all('/["\']?([\w-]+)["\']?: (Supported|Unsupported|Adapted|Planned)/', $match[2], $cells, PREG_SET_ORDER);
        test()->assertArrayNotHasKey($match[1], $rows, "{$component} has duplicate provider {$match[1]}");
        $rows[$match[1]] = array_column($cells, 2, 1);
    }

    return $rows;
}

function documentedProviderName(ProviderName $provider, string $component = 'ProviderSupport'): string
{
    return match ($provider) {
        ProviderName::Azure => 'Azure OpenAI',
        ProviderName::Vertex => 'Vertex AI',
        ProviderName::XAI => 'xAI',
        ProviderName::VoyageAI => $component === 'ExceptionSupport' ? 'Voyage AI' : 'VoyageAI',
        default => $provider->name,
    };
}

it('compares the :dataset docs provider list with the built-in Provider enum', function (string $component): void {
    $names = array_map(fn (ProviderName $name): string => documentedProviderName($name, $component), ProviderName::cases());
    $documented = array_keys(documentedProviderRows($component));
    $missing = array_diff($names, $documented);
    $extra = array_diff($documented, $names);
    test()->assertSame([], array_values($missing), "{$component} docs table is missing ".implode(', ', $missing).' from Provider enum');
    test()->assertSame([], array_values($extra), "{$component} docs table contains providers outside the built-in Provider enum: ".implode(', ', $extra));
})->with(['ProviderSupport', 'ExceptionSupport']);

it('requires every displayed capability column for :dataset', function (ProviderName $name): void {
    $row = documentedProviderRows('ProviderSupport')[documentedProviderName($name)] ?? [];
    foreach (['text', 'streaming', 'structured', 'embeddings', 'image', 'speech-to-text', 'text-to-speech', 'tools', 'documents', 'moderation'] as $column) {
        test()->assertArrayHasKey($column, $row, 'ProviderSupport docs table is missing '.documentedProviderName($name).' '.$column);
    }
})->with(ProviderName::cases());

/** @return array<string, array{ProviderName, string, string}> */
function documentedCapabilities(): array
{
    $cases = [];
    foreach (ProviderName::cases() as $name) {
        foreach ([
            'text' => 'text', 'streaming' => 'stream', 'structured' => 'structured',
            'embeddings' => 'embeddings', 'speech-to-text' => 'speechToText',
            'text-to-speech' => 'textToSpeech', 'moderation' => 'moderation',
        ] as $column => $method) {
            $cases[$name->name.' '.$column] = [$name, $column, $method];
        }
    }

    return $cases;
}

it('documents request dispatch or explicit refusal for :dataset', function (ProviderName $name, string $column, string $method): void {
    $row = documentedProviderRows('ProviderSupport')[documentedProviderName($name)] ?? [];
    test()->assertArrayHasKey($column, $row, 'ProviderSupport docs table is missing '.documentedProviderName($name).' '.$column);
    test()->assertContains($row[$column], ['Supported', 'Adapted', 'Unsupported', 'Planned']);

    // Explicit credentials prevent environment keys or Vertex credential discovery.
    $config = [
        'api_key' => 'documentation-fixture', 'url' => 'https://factcheck.invalid',
        'access_token' => 'documentation-fixture', 'credentials_path' => null,
        'project_id' => 'fixture', 'region' => 'us-central1',
    ];
    $model = 'gpt-4o';
    $schema = new ObjectSchema('answer', 'Fixture', [new StringSchema('value', 'Fixture')], ['value']);
    $invoke = match ($method) {
        'text' => fn () => Prism::text()->using($name, $model, $config)->withPrompt('Fixture')->asText(),
        'stream' => fn () => Prism::text()->using($name, $model, $config)->withPrompt('Fixture')->asStream(),
        'structured' => fn () => Prism::structured()->using($name, $model, $config)->withPrompt('Fixture')->withSchema($schema)->asStructured(),
        'embeddings' => fn () => Prism::embeddings()->using($name, $model, $config)->fromInput('Fixture')->asEmbeddings(),
        'textToSpeech' => fn () => Prism::audio()->using($name, $model, $config)->withInput('Fixture')->withVoice('fixture')->asAudio(),
        'speechToText' => fn () => Prism::audio()->using($name, $model, $config)->withInput(Audio::fromBase64(base64_encode('fixture'), 'audio/wav'))->asText(),
        'moderation' => fn () => Prism::moderation()->using($name, $model, $config)->withInput('Fixture')->asModeration(),
    };

    $implemented = CapabilityProbe::implemented($invoke, $name->name, $method);
    expect(in_array($row[$column], ['Supported', 'Adapted'], true))
        ->toBe($implemented, documentedProviderName($name).' '.$column.' '.$row[$column].' disagrees with executed request dispatch');
})->with(documentedCapabilities());

it('documents HTTP exception mappings exercised by :dataset', function (ProviderName $name): void {
    $row = documentedProviderRows('ExceptionSupport')[documentedProviderName($name, 'ExceptionSupport')] ?? [];
    $class = 'Prism\\Prism\\Providers\\'.$name->name.'\\'.$name->name;
    // Mapping a received HTTP error needs no credentials or live API client.
    $provider = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    expect($provider)->toBeInstanceOf(Provider::class);

    foreach ([
        'rateLimited' => [[429], PrismRateLimitedException::class],
        'overloaded' => [[503, 529], PrismProviderOverloadedException::class],
        'tooLarge' => [[413], PrismRequestTooLargeException::class],
    ] as $column => [$statuses, $exceptionClass]) {
        $supported = false;
        foreach ($statuses as $status) {
            $http = new Factory;
            $http->fake(['factcheck.invalid/*' => $http->response([], $status)]);
            $exception = new RequestException($http->get('https://factcheck.invalid/error'));
            try {
                $provider->handleRequestException('fixture-model', $exception);
            } catch (PrismException $error) {
                $supported = $supported || $error instanceof $exceptionClass;
            }
        }
        test()->assertArrayHasKey($column, $row, 'ExceptionSupport docs table is missing '.documentedProviderName($name, 'ExceptionSupport').' '.$column);
        expect($row[$column] === 'Supported')->toBe($supported, documentedProviderName($name).' '.$column);
    }
})->with(ProviderName::cases());
