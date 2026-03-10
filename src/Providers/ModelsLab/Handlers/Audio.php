<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\ModelsLab\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\ModelsLab\Concerns\HandlesAsyncRequests;
use Prism\Prism\Providers\ModelsLab\Maps\SpeechToTextRequestMap;
use Prism\Prism\Providers\ModelsLab\Maps\TextToSpeechRequestMap;
use Prism\Prism\ValueObjects\GeneratedAudio;

class Audio
{
    use HandlesAsyncRequests;

    public function __construct(
        protected PendingRequest $client,
        #[\SensitiveParameter] protected string $apiKey
    ) {}

    public function handleTextToSpeech(TextToSpeechRequest $request): AudioResponse
    {
        $response = $this->client->post(
            'voice/text_to_speech',
            TextToSpeechRequestMap::map($request, $this->apiKey)
        );

        $data = $response->json();

        $this->validateResponse($data);

        if (($data['status'] ?? '') === 'processing') {
            $data = $this->pollForResult(
                $this->client,
                $data['fetch_result'] ?? '',
                $this->apiKey
            );
        }

        $audioUrl = $data['output'][0] ?? null;

        if (! $audioUrl) {
            throw PrismException::providerResponseError('No audio URL in response');
        }

        /** @var Response $audioResponse */
        $audioResponse = Http::get($audioUrl);
        $audioContent = $audioResponse->body();
        $base64Audio = base64_encode($audioContent);

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $base64Audio,
            ),
        );
    }

    public function handleSpeechToText(SpeechToTextRequest $request): TextResponse
    {
        $audioInput = $request->input();

        $audioUrl = $audioInput->isUrl()
            ? ($audioInput->url() ?? '')
            : 'data:'.$audioInput->mimeType().';base64,'.$audioInput->base64();

        $response = $this->client->post(
            'voice/speech_to_text',
            SpeechToTextRequestMap::map($request, $this->apiKey, $audioUrl)
        );

        $data = $response->json();

        $this->validateResponse($data);

        if (($data['status'] ?? '') === 'processing') {
            $data = $this->pollForResult(
                $this->client,
                $data['fetch_result'] ?? '',
                $this->apiKey
            );
        }

        $text = $this->extractTranscriptionText($data);

        return new TextResponse(
            text: $text,
            additionalContent: $data,
        );
    }

    /**
     * Extract transcription text from the response data.
     * Handles direct string output, array output with text key, and URL-based JSON output.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractTranscriptionText(array $data): string
    {
        $output = $data['output'] ?? '';

        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            if (isset($output['text'])) {
                return $output['text'];
            }

            $firstItem = $output[0] ?? '';

            if (is_string($firstItem) && str_starts_with($firstItem, 'http')) {
                /** @var Response $response */
                $response = Http::get($firstItem);
                $jsonData = $response->json();

                if (is_array($jsonData) && isset($jsonData[0]['text'])) {
                    return $jsonData[0]['text'];
                }

                return '';
            }

            if (is_array($firstItem) && isset($firstItem['text'])) {
                return $firstItem['text'];
            }

            return is_string($firstItem) ? $firstItem : '';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateResponse(array $data): void
    {
        if (($data['status'] ?? '') === 'error') {
            $message = $data['message'] ?? $data['messege'] ?? 'Unknown error from ModelsLab API';

            throw PrismException::providerResponseError(
                $this->formatErrorMessage($message)
            );
        }
    }

    /**
     * Format error message from various response formats.
     */
    protected function formatErrorMessage(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_array($message)) {
            $errors = [];
            foreach ($message as $fieldErrors) {
                $errors[] = is_array($fieldErrors) ? implode(' ', $fieldErrors) : (string) $fieldErrors;
            }

            return implode(' ', $errors);
        }

        return 'Unknown error from ModelsLab API';
    }
}
