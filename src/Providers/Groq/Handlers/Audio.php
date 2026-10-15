<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Groq\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\GeneratesAudioFilename;
use Prism\Prism\Providers\Groq\Concerns\MapsUsage;
use Prism\Prism\Providers\Groq\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Groq\Maps\TextToSpeechRequestMapper;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use GeneratesAudioFilename;
    use MapsUsage;
    use ProcessRateLimits;

    public function __construct(protected PendingRequest $client) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $mapper = new TextToSpeechRequestMapper($request);

        /** @var Response $response */
        $response = $this->client->post('audio/speech', $mapper->toPayload());

        if (! $response->successful()) {
            throw new Exception('Failed to generate audio: '.$response->body());
        }

        $audioContent = $response->body();
        $base64Audio = base64_encode($audioContent);

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
            ),
        );
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        $filename = $this->generateFilename($request->input()->mimeType());

        /** @var Response $response */
        $response = $this
            ->client
            ->attach(
                'file',
                $request->input()->resource(),
                $filename,
                ['Content-Type' => $request->input()->mimeType()]
            )
            ->post('audio/transcriptions', Arr::whereNotNull([
                'model' => $request->model(),
                'language' => $request->providerOptions('language') ?? null,
                'prompt' => $request->providerOptions('prompt') ?? null,
                'response_format' => $request->providerOptions('response_format') ?? null,
                'temperature' => $request->providerOptions('temperature') ?? null,
            ]));

        if (json_validate($response->body())) {
            $data = $response->json();

            if (! $response->successful()) {
                throw new Exception('Failed to transcribe audio: '.$response->body());
            }

            return new TextResponse(
                text: $data['text'] ?? '',
                usage: $this->mapTranscriptionUsage($data['usage'] ?? null),
                additionalContent: $this->transcriptionAdditionalContent($data),
            );
        }

        // Handle other response formats like vtt
        return new TextResponse(
            text: $response->body(),
        );
    }

    /**
     * Groq bills transcription by audio duration and its responses carry no
     * `usage`. Token counts are mapped only when a response reports them.
     */
    protected function mapTranscriptionUsage(mixed $usage): ?Usage
    {
        if (! is_array($usage) || $this->isDurationUsage($usage)) {
            return null;
        }

        $promptTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;

        if ($promptTokens === null && $completionTokens === null) {
            return null;
        }

        return $this->mapUsage([
            ...$usage,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transcriptionAdditionalContent(array $data): array
    {
        $usage = $data['usage'] ?? null;

        if (! is_array($usage) || ! $this->isDurationUsage($usage)) {
            return $data;
        }

        return [...$data, 'usage_seconds' => $usage['seconds'] ?? null];
    }

    /**
     * @param  array<array-key, mixed>  $usage
     */
    protected function isDurationUsage(array $usage): bool
    {
        return ($usage['type'] ?? null) === 'duration';
    }
}
