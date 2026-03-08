<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;

it('maps incomplete status to Length', function (): void {
    expect(FinishReasonMap::map('incomplete'))->toBe(FinishReason::Length);
});

it('maps length status to Length', function (): void {
    expect(FinishReasonMap::map('length'))->toBe(FinishReason::Length);
});

it('maps failed status to Error', function (): void {
    expect(FinishReasonMap::map('failed'))->toBe(FinishReason::Error);
});

it('maps completed status with message type to Stop', function (): void {
    expect(FinishReasonMap::map('completed', 'message'))->toBe(FinishReason::Stop);
});

it('maps completed status with function_call type to ToolCalls', function (): void {
    expect(FinishReasonMap::map('completed', 'function_call'))->toBe(FinishReason::ToolCalls);
});

it('maps completed status with any _call suffix to ToolCalls', function (): void {
    expect(FinishReasonMap::map('completed', 'web_search_call'))->toBe(FinishReason::ToolCalls);
});

it('maps completed status with reasoning type to Unknown', function (): void {
    expect(FinishReasonMap::map('completed', 'reasoning'))->toBe(FinishReason::Unknown);
});

it('maps completed status with unknown type to Unknown', function (): void {
    expect(FinishReasonMap::map('completed', 'other'))->toBe(FinishReason::Unknown);
});

it('maps empty status to Unknown', function (): void {
    expect(FinishReasonMap::map(''))->toBe(FinishReason::Unknown);
});

it('maps unrecognized status to Unknown', function (): void {
    expect(FinishReasonMap::map('some_new_status'))->toBe(FinishReason::Unknown);
});
