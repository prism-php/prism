<?php

declare(strict_types=1);

namespace Tests\Fixtures\ToolFailure;

use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolOutput;
use RuntimeException;

class Caller
{
    use CallsTools;

    public function stream(array $tools, array $calls, array &$results): array
    {
        return iterator_to_array($this->callToolsAndYieldEvents($tools, $calls, 'message', $results));
    }

    public static function tool(string $scenario): Tool
    {
        $tool = (new Tool)->as('probe')->for('Process fixture')->withoutErrorHandling()->concurrent();

        return $tool->using(match ($scenario) {
            'native' => static fn () => throw new RuntimeException('synthetic-native-secret'),
            'caught' => static fn () => throw new PrismException('synthetic-native-secret'),
            'raw-error' => static fn () => throw new \Error('synthetic-native-secret'),
            'promoted' => static fn () => throw new PromotedFailure('synthetic-native-secret', 0),
            'object-parameter' => static fn () => throw new ObjectParameterFailure((object) ['nested' => (object) []]),
            'unstored-parameters' => static fn () => throw new UnstoredConstructorFailure('synthetic-native-secret', 7),
            'invalid-utf8' => static fn () => throw new RuntimeException("synthetic-\xff"),
            default => static fn (): ToolOutput => new ToolOutput('{"success":false,"error":"synthetic customer"}'),
        });
    }

    /** @return array{0: array<Tool>, 1: array<ToolCall>} */
    public static function batch(string $scenario): array
    {
        if (! in_array($scenario, ['two-failures', 'mixed'], true)) {
            return [[self::tool($scenario)], [new ToolCall('call', 'probe', '{}')]];
        }
        $first = (new Tool)->as('weather')->for('First')->withStringParameter('city', 'City')->withoutErrorHandling()->concurrent();
        if ($scenario === 'mixed') {
            $first->using(static fn (string $city): string => 'unchanged sibling result');
        } else {
            $first->using(static function (string $city): never {
                usleep(100000);
                throw new RuntimeException('first-native-secret');
            });
        }
        $second = (new Tool)->as('search')->for('Second')->withStringParameter('query', 'Query')->withoutErrorHandling()->concurrent()
            ->using(static fn (string $query) => throw new RuntimeException('second-native-secret'));

        return [[$first, $second], [new ToolCall('first', 'weather', '{"city":"fixture"}'), new ToolCall('second', 'search', '{"query":"fixture"}')]];
    }
}
