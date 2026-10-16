<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Support;

use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;

class OutputItems
{
    protected const FINAL_ANSWER_PHASE = 'final_answer';

    /** @var array<int, string> */
    protected const REPLAYABLE_TYPES = ['message', 'reasoning', 'function_call'];

    /**
     * Keep the replayable output items (messages, reasoning, function calls) in
     * their original order, normalised so they can be sent back as input.
     *
     * @param  array<array-key, mixed>  $output
     * @return array<int, array<string, mixed>>
     */
    public static function replayable(array $output): array
    {
        return array_values(array_map(
            self::normalize(...),
            array_filter($output, self::isReplayable(...))
        ));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normalize(array $item): array
    {
        if (($item['type'] ?? null) === 'reasoning' && ! is_array($item['summary'] ?? null)) {
            $item['summary'] = [];
        }

        return $item;
    }

    public static function isReplayable(mixed $item): bool
    {
        return is_array($item) && in_array($item['type'] ?? null, self::REPLAYABLE_TYPES, true);
    }

    /**
     * Text of the final answer messages, or of every message when the model
     * does not label phases (tool steps then surface their commentary).
     *
     * @param  array<array-key, mixed>  $output
     */
    public static function text(array $output): string
    {
        $messages = self::messages($output);

        if ($messages === []) {
            return self::unlabelledText($output);
        }

        $finalAnswers = array_filter($messages, self::isFinalAnswer(...));

        return implode('', array_map(
            self::messageText(...),
            $finalAnswers === [] ? $messages : $finalAnswers
        ));
    }

    /**
     * Text of the message that carries the final answer: the last
     * final_answer message, otherwise the last message.
     *
     * @param  array<array-key, mixed>  $output
     */
    public static function finalMessageText(array $output): string
    {
        $messages = self::messages($output);

        if ($messages === []) {
            return self::unlabelledText($output);
        }

        $finalAnswer = Arr::last($messages, self::isFinalAnswer(...)) ?? Arr::last($messages);

        return is_array($finalAnswer) ? self::messageText($finalAnswer) : '';
    }

    /**
     * @param  array<array-key, mixed>  $output
     */
    public static function phase(array $output): ?string
    {
        $lastMessage = Arr::last(self::messages($output));

        return is_array($lastMessage) ? ($lastMessage['phase'] ?? null) : null;
    }

    /**
     * @param  array<array-key, mixed>  $output
     */
    public static function refusal(array $output): ?string
    {
        $refusals = [];

        foreach (self::messages($output) as $message) {
            foreach (self::contentParts($message) as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    $refusals[] = (string) ($part['refusal'] ?? '');
                }
            }
        }

        return $refusals === [] ? null : implode('', $refusals);
    }

    /**
     * @param  array<array-key, mixed>  $output
     */
    public static function hasFunctionCall(array $output): bool
    {
        foreach ($output as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'function_call') {
                return true;
            }
        }

        return false;
    }

    /**
     * Replay state for a step. Only a step that finished with tool calls has
     * them executed, so any other step keeps no function calls (they would
     * replay without a function_call_output).
     *
     * @param  array<array-key, mixed>  $output
     * @return array<string, mixed>
     */
    public static function replayContent(array $output, FinishReason $finishReason): array
    {
        $items = self::replayable($output);

        if ($finishReason !== FinishReason::ToolCalls) {
            $items = self::withoutUnansweredCalls($items);
        }

        return Arr::whereNotNull([
            'output_items' => $items === [] ? null : $items,
            'phase' => self::phase($output),
            'refusal' => self::refusal($output),
        ]);
    }

    /**
     * Drops function calls, and the reasoning items that led only to them:
     * a reasoning item is kept when the next non-reasoning item after it is
     * kept, since reasoning replayed without its following item is rejected.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function withoutUnansweredCalls(array $items): array
    {
        $kept = [];
        $followedByKeptItem = false;

        foreach (array_reverse($items) as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'function_call') {
                $followedByKeptItem = false;

                continue;
            }

            if ($type === 'reasoning' && ! $followedByKeptItem) {
                continue;
            }

            $followedByKeptItem = true;
            $kept[] = $item;
        }

        return array_reverse($kept);
    }

    /**
     * @param  array<array-key, mixed>  $output
     * @return array<int, array<string, mixed>>
     */
    protected static function messages(array $output): array
    {
        return array_values(array_filter(
            $output,
            fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'message'
        ));
    }

    /**
     * Without any message item, fall back to the text content of the last
     * output item (an item type Prism does not know yet).
     *
     * @param  array<array-key, mixed>  $output
     */
    protected static function unlabelledText(array $output): string
    {
        $lastItem = Arr::last($output);

        return is_array($lastItem) ? self::messageText($lastItem) : '';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected static function isFinalAnswer(array $message): bool
    {
        return ($message['phase'] ?? null) === self::FINAL_ANSWER_PHASE;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected static function messageText(array $message): string
    {
        $text = '';

        foreach (self::contentParts($message) as $part) {
            if (($part['type'] ?? null) === 'output_text') {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, array<string, mixed>>
     */
    protected static function contentParts(array $message): array
    {
        $content = $message['content'] ?? [];

        return is_array($content) ? array_values(array_filter($content, is_array(...))) : [];
    }
}
