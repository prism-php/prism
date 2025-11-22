<?php

declare(strict_types=1);

use Prism\Prism\Providers\Perplexity\Concerns\ExtractsReasoning;

it('extracts the reasoning correctly', function (string $rawContent, ?string $expectedOutput): void {
    $testClass = new class
    {
        use ExtractsReasoning;

        public function testExtractsReasoning(string $rawContent): ?string
        {
            return $this->extractsReasoning($rawContent);
        }
    };

    $this->assertEquals($expectedOutput, (new $testClass)->testExtractsReasoning($rawContent));
})->with([
    'with reasoning block' => [
        'rawContent' => <<<'EOT'
            <think>
            The model reasoning process
            </think>

            { "foo": "bar" }
        EOT,
        'expectedOutput' => 'The model reasoning process',
    ],
    'without reasoning block' => [
        'rawContent' => <<<'EOT'
            { "foo": "bar" }
        EOT,
        'expectedOutput' => null,
    ],
]);
