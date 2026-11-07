<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Handlers;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Concerns\GeneratesAudioFilename;
use Prism\Prism\Providers\Anthropic\Concerns\ProcessesRateLimits as ConcernsProcessesRateLimits;
use Prism\Prism\Providers\OpenAI\Concerns\ValidatesResponse;
use Prism\Prism\Providers\OpenAI\Maps\TextToSpeechRequestMapper;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Usage;

class Audio
{
    use ConcernsProcessesRateLimits;
    use GeneratesAudioFilename;
    use ValidatesResponse;

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
        /** @var Response $response */
        $response = $this
            ->client
            ->attach(
                'file',
                $request->input()->resource(),
                $this->generateFilename($request->input()->mimeType()),
                ['Content-Type' => $request->input()->mimeType()]
            )
            ->post('audio/transcriptions', Arr::whereNotNull([
                'model' => $request->model(),
                'language' => $request->providerOptions('language') ?? null,
                'prompt' => $request->providerOptions('prompt') ?? null,
                'response_format' => $request->providerOptions('response_format') ?? null,
                'service_tier' => $request->providerOptions('service_tier') ?? null,
                'temperature' => $request->providerOptions('temperature') ?? null,
                'chunking_strategy' => $request->providerOptions('chunking_strategy') ?? null,
            ]));

        if (json_validate($response->body())) {
            $data = $response->json();

            $this->validateResponse($response);

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

    protected function mapTranscriptionUsage(mixed $usage): ?Usage
    {
        if (! is_array($usage) || $this->isDurationUsage($usage)) {
            return null;
        }

        return new Usage(
            promptTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
        );
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
