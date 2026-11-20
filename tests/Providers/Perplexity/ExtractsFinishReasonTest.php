<?php

declare(strict_types=1);

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Perplexity\Concerns\ExtractsFinishReason;

it('extracts finish reason correctly from response data', function (array $data, FinishReason $expected): void {
    $testClass = new class
    {
        use ExtractsFinishReason;

        public function testExtractsFinishReason(array $data): FinishReason
        {
            return $this->extractsFinishReason($data);
        }
    };

    expect((new $testClass)->testExtractsFinishReason($data))->toBe($expected);
})->with([
    // The Agent API reports a run status rather than a per-choice finish
    // reason. Failed and cancelled runs never reach here — assertRunSucceeded
    // throws on those first.
    'completed run' => [
        'data' => ['status' => 'completed'],
        'expected' => FinishReason::Stop,
    ],
    'incomplete does not imply a token limit' => [
        'data' => ['status' => 'incomplete'],
        'expected' => FinishReason::Unknown,
    ],
    'sonar-shaped length finish reason' => [
        'data' => [
            'choices' => [
                ['finish_reason' => 'length'],
            ],
        ],
        'expected' => FinishReason::Length,
    ],
    'sonar-shaped undocumented finish reason' => [
        'data' => [
            'choices' => [
                ['finish_reason' => 'content_filter'],
            ],
        ],
        'expected' => FinishReason::Unknown,
    ],
    'run status wins over a per-choice finish reason' => [
        'data' => [
            'status' => 'incomplete',
            'choices' => [
                ['finish_reason' => 'length'],
            ],
        ],
        'expected' => FinishReason::Unknown,
    ],
    'unrecognised status' => [
        'data' => ['status' => 'something_new'],
        'expected' => FinishReason::Unknown,
    ],
    'missing status key' => [
        'data' => ['output' => []],
        'expected' => FinishReason::Unknown,
    ],
]);
