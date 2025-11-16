<?php

namespace Prism\Prism\Providers\Perplexity\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;

class BaseHandler
{
    protected function sendRequest(PendingRequest $client, TextRequest|StructuredRequest $request): HttpResponse
    {
        // TODO: move the payload mapping to a dedicated mapper class
        $messages = collect($request->messages())
            ->map(static function (Message $message): array {
                $documentMessages = [];
                $imageMessages = [];

                if ($message instanceof UserMessage) {
                    $role = 'user';
                    $text = $message->text();

                    // TODO: handle provided files as URLs if needed
                    $documentMessages = array_map(static fn (Document $content): array => [
                        'type' => 'file_url',
                        'file_url' => [
                            'url' => $content->base64(),
                        ],
                    ], $message->documents());

                    $imageMessages = array_map(static fn (Image $image): array => [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$image->mimeType()};base64,{$image->base64()}",
                        ],
                    ], $message->images());

                } elseif ($message instanceof AssistantMessage) {
                    $role = 'assistant';
                    $text = $message->content;
                } elseif ($message instanceof SystemMessage) {
                    $role = 'system';
                    $text = $message->content;
                } else {
                    throw new RuntimeException('Could not map message type '.$message::class);
                }

                return [
                    'role' => $role,
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $text,
                        ],
                        ...$documentMessages,
                        ...$imageMessages,
                    ],
                ];
            })
            // Define custom order: system messages are always at the beginning of the list
            ->sortBy(static fn (array $message): int => $message['role'] === 'system' ? 0 : 1)
            ->values()
            ->toArray();

        $payload = array_merge([
            'model' => $request->model(),
            'messages' => $messages,
            'max_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'reasoning_effort' => $request->providerOptions('reasoning_effort'),
            'web_search_options' => $request->providerOptions('web_search_options'),
            // TODO: check if there are more Perplexity specific options to add
        ]));

        return $client->post('/chat/completions', $payload);
    }
}
