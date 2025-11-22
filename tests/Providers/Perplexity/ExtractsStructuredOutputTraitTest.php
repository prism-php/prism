<?php

declare(strict_types=1);

use Prism\Prism\Providers\Perplexity\Concerns\ExtractsStructuredOutput;

it('parses the structured output from the response correctly', function (string $rawContent, array $expectedOutput): void {
    $testClass = new class
    {
        use ExtractsStructuredOutput;

        public function testParseStructuredOutput(string $rawContent): array
        {
            return $this->parseStructuredOutput($rawContent);
        }
    };

    $this->assertEquals($expectedOutput, (new $testClass)->testParseStructuredOutput($rawContent));
})->with([
    'plain json' => [
        'rawContent' => '{"key1": "value1", "key2": 2, "key3": {"subkey": "subvalue"}}',
        'expectedOutput' => [
            'key1' => 'value1',
            'key2' => 2,
            'key3' => [
                'subkey' => 'subvalue',
            ],
        ],
    ],
    'wrapped json' => [
        'rawContent' => '
                    ```json
                    {"foo": "bar"}
                    ```
                    ',
        'expectedOutput' => ['foo' => 'bar'],
    ],
    'plain json with reasoning block' => [
        'rawContent' => <<<'EOT'
                    <think>
                    The model reasoning process
                    </think>
                    
                    { "foo": "bar" }
                EOT,
        'expectedOutput' => ['foo' => 'bar'],
    ],
    'wrapped json with reasoning block' => [
        'rawContent' => <<<'EOT'
            <think>
            The model reasoning process
            </think>
            
            ```json
            { "foo": "bar" }
            ```
        EOT,
        'expectedOutput' => ['foo' => 'bar'],
    ],
]);
