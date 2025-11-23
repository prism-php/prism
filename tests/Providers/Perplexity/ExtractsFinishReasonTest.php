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
    'stop finish reason' => [
        'data' => [
            'choices' => [
                ['finish_reason' => 'stop'],
            ],
        ],
        'expected' => FinishReason::Stop,
    ],
    'length finish reason (unsupported -> unknown)' => [
        'data' => [
            'choices' => [
                ['finish_reason' => 'length'],
            ],
        ],
        'expected' => FinishReason::Unknown,
    ],
    'missing finish reason key' => [
        'data' => [
            'choices' => [
                ['content' => 'Hello world'],
            ],
        ],
        'expected' => FinishReason::Unknown,
    ],
    'null finish reason value' => [
        'data' => [
            'choices' => [
                ['finish_reason' => null],
            ],
        ],
        'expected' => FinishReason::Unknown,
    ],
]);
