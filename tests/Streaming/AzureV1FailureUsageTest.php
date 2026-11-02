<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\Azure\Handlers\Stream;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/** A process-local native fopen transport; never opens a network connection. */
class OwnedAzureStreamWrapper
{
    public $context;

    public static string $body = '';

    public static ?Throwable $failure = null;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($path !== 'https://offline.invalid/openai/v1/chat/completions') {
            throw new LogicException('Unexpected URL refused by native fixture.');
        }

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->position >= strlen(self::$body) && self::$failure) {
            throw self::$failure;
        }
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return ! self::$failure instanceof Throwable && $this->position >= strlen(self::$body);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}

it('carries Azure v1 usage from the native resource before an unchanged read failure', function (bool $fail): void {
    $start = ['id' => 'owned', 'model' => 'owned', 'choices' => [['index' => 0, 'delta' => ['content' => 'x'], 'finish_reason' => null]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3]];
    $finish = ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4]];
    OwnedAzureStreamWrapper::$body = 'data: '.json_encode($start)."\n\n".($fail ? '' : 'data: '.json_encode($finish)."\n\n");
    $sentinel = $fail ? new RuntimeException('owned native resource failure') : null;
    OwnedAzureStreamWrapper::$failure = $sentinel;
    expect(stream_wrapper_unregister('https'))->toBeTrue();
    expect(stream_wrapper_register('https', OwnedAzureStreamWrapper::class))->toBeTrue();
    $events = [];
    $caught = null;
    try {
        $handler = new Stream(Http::baseUrl('https://offline.invalid'), new Azure('https://offline.invalid/openai/v1', 'owned-fake', 'v1'));
        foreach ($handler->handle(new Request(model: 'owned', messages: [new UserMessage('owned')])) as $event) {
            $events[] = $event;
        }
    } catch (Throwable $exception) {
        $caught = $exception;
    } finally {
        stream_wrapper_restore('https');
        OwnedAzureStreamWrapper::$failure = null;
    }
    if ($fail) {
        $errors = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ErrorEvent));
        expect($caught)->toBe($sentinel)->and($errors)->toHaveCount(1)
            ->and($errors[0]->failureUsage->usage->promptTokens)->toBe(11)
            ->and($errors[0]->failureUsage->usage->completionTokens)->toBe(3);
    } else {
        $end = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEndEvent))[0];
        expect($caught)->toBeNull()->and($end->usage->promptTokens)->toBe(11)->and($end->usage->completionTokens)->toBe(4);
    }
})->with([true, false]);
