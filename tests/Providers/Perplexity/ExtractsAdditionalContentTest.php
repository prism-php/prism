<?php

declare(strict_types=1);

use Prism\Prism\Providers\Perplexity\Concerns\ExtractsAdditionalContent;

it('extracts the reasoning correctly from the raw response', function (string $rawResponse, ?string $expectedOutput): void {
    $testClass = new class
    {
        use ExtractsAdditionalContent;

        public function testExtractsReasoning(string $rawResponse): ?string
        {
            return $this->extractsReasoning($rawResponse);
        }
    };

    $this->assertEquals($expectedOutput, (new $testClass)->testExtractsReasoning($rawResponse));
})->with([
    'with reasoning block' => [
        'rawResponse' => <<<'EOT'
            <think>
            The model reasoning process
            </think>

            { "foo": "bar" }
        EOT,
        'expectedOutput' => 'The model reasoning process',
    ],
    'without reasoning block' => [
        'rawResponse' => <<<'EOT'
            { "foo": "bar" }
        EOT,
        'expectedOutput' => null,
    ],
]);

it('extracts additional content from the response data', function (): void {
    $testClass = new class
    {
        use ExtractsAdditionalContent;

        public function testExtractsAdditionalContent(array $data): array
        {
            return $this->extractsAdditionalContent($data);
        }
    };

    $responseData = [
        'citations' => ['Citation 1', 'Citation 2'],
        'search_results' => ['Result 1', 'Result 2'],
        'choices' => [
            [
                'message' => [
                    'content' => <<<'EOT'
                        <think>
                        The model reasoning process
                        </think>

                        { "foo": "bar" }
                    EOT,
                ],
            ],
        ],
    ];

    $expectedOutput = [
        'citations' => ['Citation 1', 'Citation 2'],
        'search_results' => ['Result 1', 'Result 2'],
        'reasoning' => 'The model reasoning process',
    ];

    $this->assertEquals($expectedOutput, (new $testClass)->testExtractsAdditionalContent($responseData));
});
