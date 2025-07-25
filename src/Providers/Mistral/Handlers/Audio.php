<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Providers\Mistral\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Mistral\Maps\SpeechToTextRequestMapper;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use ProcessRateLimits;

    public function __construct(protected PendingRequest $client) {}

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        $mapper = new SpeechToTextRequestMapper($request);
        $response = $this->client->asMultipart()->post('audio/transcriptions', $mapper->toPayload());

        if (! $response->successful()) {
            throw new \Exception('Failed to transcribe audio: '.$response->body());
        }

        $data = $response->json();

        $usage = null;
        if (isset($data['usage'])) {
            $usage = new Usage(
                promptTokens: $data['usage']['prompt_tokens'] ?? 0,
                completionTokens: $data['usage']['completion_tokens'] ?? 0,
            );
        }

        return new TextResponse(
            text: $data['text'] ?? '',
            usage: $usage,
            additionalContent: $data,
        );
    }
}
