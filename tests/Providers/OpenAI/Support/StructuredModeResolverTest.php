<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Support;

use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;

it('resolves structured mode for exact-match models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-4o',
    'gpt-4o-mini',
    'gpt-4o-mini-2024-07-18',
    'gpt-4o-2024-08-06',
    'chatgpt-4o-latest',
    'o3-mini',
    'o3-mini-2025-01-31',
]);

it('resolves structured mode for gpt-4.1+ family models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-4.1',
    'gpt-4.1-nano',
    'gpt-4.1-mini',
    'gpt-4.1-nano-2025-04-14',
    'gpt-4.1-mini-2025-04-14',
    'gpt-4.5-preview',
    'gpt-4.5-preview-2025-02-27',
]);

it('resolves structured mode for gpt-5 family models', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-5',
    'gpt-5-mini',
    'gpt-5-nano',
    'gpt-5-mini-2025-08-07',
    'gpt-5-nano-2025-08-07',
    'gpt-5.1',
    'gpt-5.1-2025-10-01',
    'gpt-5.2',
    'gpt-5.2-2025-12-11',
    'gpt-5.4',
]);

it('resolves structured mode for current and future model families', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-6.1-sol',
    'gpt-6-sol',
    'gpt-6-luna',
    'gpt-6-astra',
    'gpt-5.6-sol',
    'gpt-5.6-luna',
    'gpt-5.6-terra',
    'gpt-5.5',
    'o3',
    'o3-pro',
    'o4-mini',
    'o4-mini-2025-04-16',
    'o1',
]);

it('resolves structured mode for unknown and custom model names', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'some-custom-model',
    'my-proxy/gpt-6.1-sol',
    'gpt-7',
]);

it('resolves json mode for legacy models without json_schema support', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Json);
})->with([
    'gpt-4',
    'gpt-4-turbo',
    'gpt-4-turbo-2024-04-09',
    'gpt-4-0613',
    'gpt-4-0125-preview',
    'gpt-4-1106-preview',
    'gpt-3.5-turbo',
    'gpt-3.5-turbo-0125',
    'gpt-4o-2024-05-13',
]);

it('resolves fine-tuned models from their base model', function (string $model, StructuredMode $mode): void {
    expect(StructuredModeResolver::forModel($model))->toBe($mode);
})->with([
    ['ft:gpt-4.1-mini-2025-04-14:acme:support:abc123', StructuredMode::Structured],
    ['ft:gpt-6-luna:acme:support:abc123', StructuredMode::Structured],
    ['ft:gpt-3.5-turbo-0125:acme:support:abc123', StructuredMode::Json],
    ['ft:gpt-4-0613:acme:support:abc123', StructuredMode::Json],
]);

it('throws for unsupported models', function (string $model): void {
    StructuredModeResolver::forModel($model);
})->with([
    'o1-mini',
    'o1-mini-2024-09-12',
    'o1-preview',
    'o1-preview-2024-09-12',
])->throws(PrismException::class);

// OpenAI's current roster, measured by prism-provider-watch on 2026-10-15. The
// prefix list stopped at gpt-5 and o3-mini, so every one of these silently got
// JSON mode, where the schema is not enforced (prism#83).
it('resolves structured mode for current and future OpenAI families', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-6-sol',
    'gpt-6-luna',
    'gpt-6-astra',
    'gpt-6.1-sol',
    'gpt-7',
    'gpt-10-mini',
    'o1',
    'o1-pro',
    'o3',
    'o3-pro',
    'o4-mini',
    'o5',
    'ft:gpt-6-sol:my-org:custom-name:abc123',
    'ft:o4-mini:my-org:custom-name:abc123',
]);

// Only the short legacy list keeps json mode; a family pattern must not
// widen it to names that merely start like one.
it('keeps json mode for legacy chat models only', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Json);
})->with([
    'gpt-4',
    'gpt-3.5-turbo-0125',
]);

// Upstream's contract (prism-php/prism#1043): every name outside the legacy
// list gets a strict json_schema, including non-chat and non-OpenAI names. The
// fork's prism#83 resolver kept these on json mode; that is superseded.
it('resolves structured mode for names outside the chat families', function (string $model): void {
    expect(StructuredModeResolver::forModel($model))->toBe(StructuredMode::Structured);
})->with([
    'gpt-image-1',
    'gpt-realtime-2.1',
    'omni-moderation-latest',
    'ollama-llama3',
]);
