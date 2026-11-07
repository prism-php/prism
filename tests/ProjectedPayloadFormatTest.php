<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\DescribesProjectedPayload;
use Prism\Prism\Contracts\ProjectsRequestPayload;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('declares the format of its actual inline-image projection without dispatching', function (
    string $name,
    ?string $apiFormat,
    string $format,
    string $field,
    string $imageType,
): void {
    Http::preventStrayRequests();
    Http::fake();
    $options = ['api_key' => 'format-test-key', 'url' => 'https://format.invalid/v1'];
    if ($apiFormat !== null) {
        $options['api_format'] = $apiFormat;
    }
    $generation = Prism::text()->using($name, 'test-model', $options)->withMessages([
        new UserMessage('Describe', [Image::fromBase64('YWJj', 'image/png')]),
    ]);
    $provider = $generation->provider();
    $request = $generation->toRequest();
    $before = serialize($request);

    expect($provider)->toBeInstanceOf(DescribesProjectedPayload::class)
        ->and($provider->projectedPayloadFormat())->toBe($format);
    $payload = $provider->projectPayload($request, 'text');
    expect($payload)->toBeArray()
        ->and($payload[$field][0]['content'][1]['type'])->toBe($imageType)
        ->and($provider->projectedPayloadFormat())->toBe($format)
        ->and(serialize($request))->toBe($before);
    Http::assertNothingSent();
})->with([
    'Anthropic' => ['anthropic', null, 'anthropic-messages', 'messages', 'image'],
    'OpenAI default' => ['openai', null, 'openai-responses', 'input', 'input_image'],
    'OpenAI Responses' => ['openai', 'responses', 'openai-responses', 'input', 'input_image'],
    'OpenAI Chat Completions' => ['openai', 'chat_completions', 'openai-chat-completions', 'messages', 'image_url'],
    'OpenAI existing fallback' => ['openai', 'unrecognised-existing-value', 'openai-responses', 'input', 'input_image'],
]);

it('allows a decorator to forward both projection and format without changing the body', function (string $name, string $apiFormat, string $format): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = Prism::text()->using($name, 'test-model', [
        'api_key' => 'format-test-key', 'url' => 'https://format.invalid/v1', 'api_format' => $apiFormat,
    ])->withPrompt('Keep this body');
    $inner = $generation->provider();
    $request = $generation->toRequest();
    $decorator = new class($inner) extends Provider implements DescribesProjectedPayload
    {
        public int $projectionCalls = 0;

        public function __construct(private readonly Provider $inner) {}

        public function projectedPayloadFormat(): ?string
        {
            return $this->inner instanceof DescribesProjectedPayload ? $this->inner->projectedPayloadFormat() : null;
        }

        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            $this->projectionCalls++;

            return $this->inner->projectPayload($request, $mode);
        }
    };

    expect($decorator->projectedPayloadFormat())->toBe($format)
        ->and($decorator->projectionCalls)->toBe(0)
        ->and($decorator->projectPayload($request, 'text'))->toBe($inner->projectPayload($request, 'text'))
        ->and($decorator->projectionCalls)->toBe(1);
    Http::assertNothingSent();
})->with([
    'Anthropic decorator' => ['anthropic', 'responses', 'anthropic-messages'],
    'Responses decorator' => ['openai', 'responses', 'openai-responses'],
    'Chat decorator' => ['openai', 'chat_completions', 'openai-chat-completions'],
]);

it('keeps legacy projection implementers compatible without a format declaration', function (): void {
    $legacy = new class implements ProjectsRequestPayload
    {
        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            return null;
        }
    };
    $base = new class extends Provider {};

    expect($legacy)->toBeInstanceOf(ProjectsRequestPayload::class)
        ->not->toBeInstanceOf(DescribesProjectedPayload::class)
        ->and($base)->not->toBeInstanceOf(DescribesProjectedPayload::class)
        ->and(get_class_methods(ProjectsRequestPayload::class))->toBe(['projectPayload']);
});

it('does not make a format declaration imply projection support', function (): void {
    $metadata = new class implements DescribesProjectedPayload
    {
        public function projectedPayloadFormat(): string
        {
            return 'anthropic-messages';
        }
    };

    expect($metadata->projectedPayloadFormat())->toBe('anthropic-messages')
        ->and($metadata)->not->toBeInstanceOf(ProjectsRequestPayload::class);
});

it('allows undisclosed and future format identifiers without adding projection methods', function (): void {
    $metadata = new class implements DescribesProjectedPayload
    {
        public ?string $format = null;

        public function projectedPayloadFormat(): ?string
        {
            return $this->format;
        }
    };

    expect($metadata->projectedPayloadFormat())->toBeNull();
    $metadata->format = 'future-provider-format';
    expect($metadata->projectedPayloadFormat())->toBe('future-provider-format');
});
