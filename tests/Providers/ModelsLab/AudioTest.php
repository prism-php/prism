<?php

declare(strict_types=1);

namespace Tests\Providers\ModelsLab;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;

beforeEach(function (): void {
    config()->set('prism.providers.modelslab.api_key', 'test-api-key');
});

describe('Text-to-Speech', function (): void {
    it('can generate audio with text-to-speech', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'success',
                'output' => ['https://example.com/audio.mp3'],
            ], 200),
            'example.com/audio.mp3' => Http::response('fake-audio-content', 200),
        ]);

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Hello world!')
            ->withVoice('madison')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->toBe(base64_encode('fake-audio-content'));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'text_to_speech')) {
                return true;
            }

            $data = $request->data();

            return $request->url() === 'https://modelslab.com/api/v6/voice/text_to_speech' &&
                   $data['prompt'] === 'Hello world!' &&
                   $data['voice_id'] === 'madison' &&
                   $data['key'] === 'test-api-key';
        });
    });

    it('can generate audio with language option', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'success',
                'output' => ['https://example.com/spanish-audio.mp3'],
            ], 200),
            'example.com/spanish-audio.mp3' => Http::response('fake-spanish-audio', 200),
        ]);

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Hola mundo!')
            ->withVoice('sofia')
            ->withProviderOptions([
                'language' => 'spanish',
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'text_to_speech')) {
                return true;
            }

            $data = $request->data();

            return $data['language'] === 'spanish';
        });
    });

    it('can generate audio with speed option', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'success',
                'output' => ['https://example.com/fast-audio.mp3'],
            ], 200),
            'example.com/fast-audio.mp3' => Http::response('fake-fast-audio', 200),
        ]);

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Quick message')
            ->withVoice('madison')
            ->withProviderOptions([
                'speed' => 1.5,
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'text_to_speech')) {
                return true;
            }

            $data = $request->data();

            return $data['speed'] === 1.5;
        });
    });

    it('uses default american english language', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'success',
                'output' => ['https://example.com/audio.mp3'],
            ], 200),
            'example.com/audio.mp3' => Http::response('fake-audio', 200),
        ]);

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Test')
            ->withVoice('madison')
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'text_to_speech')) {
                return true;
            }

            $data = $request->data();

            return $data['language'] === 'american english';
        });
    });

    it('handles async processing status for text-to-speech', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'processing',
                'fetch_result' => 'https://modelslab.com/api/v6/voice/fetch/12345',
            ], 200),
            'modelslab.com/api/v6/voice/fetch/12345' => Http::response([
                'status' => 'success',
                'output' => ['https://example.com/async-audio.mp3'],
            ], 200),
            'example.com/async-audio.mp3' => Http::response('async-audio-content', 200),
        ]);

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Hello async world!')
            ->withVoice('madison')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->base64)->toBe(base64_encode('async-audio-content'));
    });
});

describe('Speech-to-Text', function (): void {
    it('can transcribe audio with speech-to-text from base64', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'success',
                'output' => 'Hello, this is a transcription test.',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(
            base64_encode('fake-audio-content'),
            'audio/mp3'
        );

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('Hello, this is a transcription test.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://modelslab.com/api/v6/voice/speech_to_text' &&
                   $data['key'] === 'test-api-key' &&
                   str_starts_with((string) $data['init_audio'], 'data:audio/mp3;base64,');
        });
    });

    it('can transcribe audio from URL', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'success',
                'output' => 'Transcription from URL audio.',
            ], 200),
        ]);

        $audioFile = Audio::fromUrl('https://example.com/audio-file.mp3');

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('Transcription from URL audio.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['init_audio'] === 'https://example.com/audio-file.mp3';
        });
    });

    it('can transcribe with language option', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'success',
                'output' => 'Bonjour, ceci est un test.',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('french-audio'), 'audio/wav');

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->withProviderOptions([
                'language' => 'fr',
            ])
            ->asText();

        expect($response->text)->toBe('Bonjour, ceci est un test.');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['language'] === 'fr';
        });
    });

    it('can transcribe with timestamp_level option', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'success',
                'output' => [
                    'text' => 'Hello world',
                    'timestamps' => [
                        ['word' => 'Hello', 'start' => 0.0, 'end' => 0.5],
                        ['word' => 'world', 'start' => 0.5, 'end' => 1.0],
                    ],
                ],
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('audio-content'), 'audio/mp3');

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->withProviderOptions([
                'timestamp_level' => 'word',
            ])
            ->asText();

        expect($response->text)->toBe('Hello world');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['timestamp_level'] === 'word';
        });
    });

    it('includes additional content in response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'success',
                'output' => 'Test transcription',
                'language' => 'en',
                'duration' => 5.5,
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('audio'), 'audio/mp3');

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();

        expect($response->additionalContent['status'])->toBe('success');
        expect($response->additionalContent['language'])->toBe('en');
        expect($response->additionalContent['duration'])->toBe(5.5);
    });

    it('handles async processing status for speech-to-text', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'processing',
                'fetch_result' => 'https://modelslab.com/api/v6/voice/fetch/67890',
            ], 200),
            'modelslab.com/api/v6/voice/fetch/67890' => Http::response([
                'status' => 'success',
                'output' => 'Async transcription result.',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('audio-content'), 'audio/mp3');

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('Async transcription result.');
    });

    it('handles URL-based JSON output for speech-to-text', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'processing',
                'fetch_result' => 'https://modelslab.com/api/v6/voice/fetch/99999',
            ], 200),
            'modelslab.com/api/v6/voice/fetch/99999' => Http::response([
                'status' => 'success',
                'output' => ['https://pub-example.r2.dev/generations/transcript.txt'],
            ], 200),
            'pub-example.r2.dev/generations/transcript.txt' => Http::response([
                ['text' => 'This is the transcribed text from the URL.'],
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('long-audio'), 'audio/mp3');

        $response = Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('This is the transcribed text from the URL.');
    });
});
