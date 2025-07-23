<?php

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits as ConcernsProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\SpeechToTextRequestMapper;
use Prism\Prism\Providers\OpenAI\Maps\TextToSpeechRequestMapper;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use ConcernsProcessesRateLimits;

    public function __construct(protected PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $options = $request->providerOptions();
        $options['model'] = $request->model();
        $options['input'] = $request->input();
        $options['voice'] = $request->voice();

        $response = $this->client->post('audio/speech', $options);

        if (! $response->successful()) {
            throw new \Exception('Failed to generate audio: '.$response->body());
        }

        $audioContent = $response->body();
        $base64Audio = base64_encode($audioContent);

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
                type: $response->header('Content-Type') ?: 'audio/mpeg',
            ),
        );
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        $audio = $request->input();
        $options = $request->providerOptions();
        $options['model'] = $request->model();

        $response = $this->client
            ->attach('file', $audio->resource(), 'audio', ['Content-Type' => $audio->mimeType()])
            ->post('audio/transcriptions', $options);

        $data = $response->json();

        $usage = null;
        if ($data && isset($data['usage'])) {
            $usage = new Usage(
                promptTokens: $data['usage']['prompt_tokens'] ?? 0,
                completionTokens: $data['usage']['completion_tokens'] ?? 0,
            );
        }

        return new TextResponse(
            text: $data ? ($data['text'] ?? '') : $response->body(),
            usage: $usage,
            additionalContent: $data ?? [],
        );
    }
}
