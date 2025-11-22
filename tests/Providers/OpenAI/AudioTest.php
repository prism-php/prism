<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY'));
});

describe('Text-to-Speech', function (): void {
    it('can generate audio with basic tts-1 model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/audio/speech',
            'openai/tts-tts-1'
        );

        $response = Prism::audio()
            ->using('openai', 'tts-1')
            ->withInput('Hello world!')
            ->withVoice('alloy')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.com/v1/audio/speech' &&
                   $data['model'] === 'tts-1' &&
                   $data['input'] === 'Hello world!';
        });
    });

    it('can generate audio with tts-1-hd model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/audio/speech',
            'openai/tts-tts-1-hd'
        );

        $response = Prism::audio()
            ->using('openai', 'tts-1-hd')
            ->withInput('This is high quality audio')
            ->withVoice('nova')
            ->withProviderOptions([
                'voice' => 'nova',
                'response_format' => 'wav',
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['model'] === 'tts-1-hd' &&
                   $data['input'] === 'This is high quality audio' &&
                   $data['voice'] === 'nova' &&
                   $data['response_format'] === 'wav';
        });
    });

    it('can generate audio with all provider options', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/audio/speech',
            'openai/tts-all-options'
        );

        $response = Prism::audio()
            ->using('openai', 'tts-1')
            ->withInput('Custom voice and speed test')
            ->withVoice('alloy')
            ->withProviderOptions([
                'response_format' => 'opus',
                'speed' => 1.2,
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['model'] === 'tts-1' &&
                   $data['input'] === 'Custom voice and speed test' &&
                   $data['voice'] === 'alloy' &&
                   $data['response_format'] === 'opus' &&
                   $data['speed'] === 1.2;
        });
    });

    it('supports different voice options', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/audio/speech',
            'openai/tts-voice-option'
        );

        $response = Prism::audio()
            ->using('openai', 'tts-1')
            ->withInput('Testing echo voice')
            ->withVoice('echo')
            ->withProviderOptions([
                'response_format' => 'mp3',
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['voice'] === 'echo' &&
                   $data['response_format'] === 'mp3';
        });
    });
});

describe('Speech-to-Text', function (): void {
    it('can transcribe audio with whisper-1 model - base64 - json', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'openai/audio-from-base64'
        );

        $audioFile = Audio::fromBase64(
            base64_encode(file_get_contents('tests/Fixtures/slightly-caffeinated-36.mp3'))
        );

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio with whisper-1 model - from path - json', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'openai/audio-from-path'
        );

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio with whisper-1 model - from path - vtt', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'openai/audio-from-path-vtt'
        );

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'response_format' => 'vtt',
            ])
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain('00:04:34.000 --> 00:04:40.320');
        expect($response->text)->toContain("But maybe after Laracon, I'll spend a little time coming up with some stuff. But I think those are");
    });

    it('can transcribe with language and prompt options', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Bonjour, ceci est un test.',
                'language' => 'fr',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('french-audio-content'), 'audio/wav');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'language' => 'fr',
                'prompt' => 'This is a French conversation about testing.',
            ])
            ->asText();

        expect($response->text)->toBe('Bonjour, ceci est un test.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions');
    });

    it('can transcribe with verbose json response format', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'The quick brown fox jumps over the lazy dog.',
                'language' => 'en',
                'duration' => 3.84,
                'segments' => [
                    [
                        'text' => 'The quick brown fox',
                        'start' => 0.0,
                        'end' => 1.5,
                    ],
                    [
                        'text' => 'jumps over the lazy dog.',
                        'start' => 1.5,
                        'end' => 3.84,
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 12,
                    'total_tokens' => 12,
                ],
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('detailed-audio-content'), 'audio/m4a');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'response_format' => 'verbose_json',
            ])
            ->asText();

        expect($response->text)->toBe('The quick brown fox jumps over the lazy dog.');
        expect($response->usage)->not->toBeNull();
        expect($response->usage->completionTokens)->toBe(12);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions');
    });

    it('can transcribe with temperature option', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Temperature controlled transcription.',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('audio-with-temperature'), 'audio/webm');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'temperature' => 0.2,
            ])
            ->asText();

        expect($response->text)->toBe('Temperature controlled transcription.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/audio/transcriptions');
    });

    it('includes usage information when available', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Usage tracking test.',
                'usage' => [
                    'input_tokens' => 5,
                    'total_tokens' => 13,
                ],
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('usage-test-audio'), 'audio/flac');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->asText();

        expect($response->usage)->not->toBeNull();
        expect($response->usage->promptTokens)->toBe(5);
        expect($response->usage->completionTokens)->toBe(13);
    });

    it('handles transcription without usage information', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Simple transcription without usage data.',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('simple-audio'), 'audio/ogg');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('Simple transcription without usage data.');
        expect($response->usage)->toBeNull();
    });
});

describe('Audio Value Object', function (): void {
    it('can create audio from local path', function (): void {
        // Create a temporary audio file for testing with MP3-like header
        $tempFile = tempnam(sys_get_temp_dir(), 'test_audio').'.mp3';
        file_put_contents($tempFile, "\xFF\xFB\x90\x00fake-mp3-content");

        $audio = Audio::fromLocalPath($tempFile);

        expect($audio->isFile())->toBeTrue();
        expect($audio->localPath())->toBe($tempFile);
        expect($audio->mimeType())->not->toBeNull();

        unlink($tempFile);
    });

    it('can create audio from base64', function (): void {
        $base64Content = base64_encode('fake-audio-binary');
        $audio = Audio::fromBase64($base64Content, 'audio/wav');

        expect($audio->hasBase64())->toBeTrue();
        expect($audio->base64())->toBe($base64Content);
        expect($audio->mimeType())->toBe('audio/wav');
    });

    it('can create audio from raw content', function (): void {
        $rawContent = 'raw-audio-data';
        $audio = Audio::fromRawContent($rawContent, 'audio/mp3');

        expect($audio->hasRawContent())->toBeTrue();
        expect($audio->rawContent())->toBe($rawContent);
        expect($audio->mimeType())->toBe('audio/mp3');
    });

    it('can create audio from url', function (): void {
        $url = 'https://example.com/audio.mp3';
        $audio = Audio::fromUrl($url);

        expect($audio->isUrl())->toBeTrue();
        expect($audio->url())->toBe($url);
    });

    it('can create resource for file uploads', function (): void {
        $audio = Audio::fromBase64(base64_encode('test-audio-content'), 'audio/wav');
        $resource = $audio->resource();

        expect($resource)->toBeResource();
        expect(stream_get_contents($resource))->toBe('test-audio-content');

        fclose($resource);
    });
});

describe('GeneratedAudio Value Object', function (): void {
    it('can check if audio has base64 data', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response(
                'audio-content-test',
                200,
                ['Content-Type' => 'audio/mpeg']
            ),
        ]);

        $response = Prism::audio()
            ->using('openai', 'tts-1')
            ->withInput('Test audio generation')
            ->withVoice('alloy')
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
    });
});

describe('Speech-to-Text Response', function (): void {
    it('can handle complex transcription responses', function (): void {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Complex transcription with metadata.',
                'language' => 'en',
                'duration' => 5.2,
                'segments' => [
                    ['text' => 'Complex transcription', 'start' => 0.0, 'end' => 2.1],
                    ['text' => 'with metadata.', 'start' => 2.1, 'end' => 5.2],
                ],
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('complex-audio'), 'audio/mp3');

        $response = Prism::audio()
            ->using('openai', 'whisper-1')
            ->withInput($audioFile)
            ->withProviderOptions(['response_format' => 'verbose_json'])
            ->asText();

        expect($response->text)->toBe('Complex transcription with metadata.');
        expect($response->text)->not->toBeEmpty();
        expect($response->additionalContent['language'])->toBe('en');
        expect($response->additionalContent['duration'])->toBe(5.2);
        expect($response->additionalContent['segments'])->toHaveCount(2);
    });
});

it('uses meta to set service_tier', function (): void {
    $serviceTier = 'priority';

    Http::fake([
        'api.openai.com/v1/audio/transcriptions' => Http::response([
            'text' => 'Some Audio',
        ], 200),
    ]);

    $audioFile = Audio::fromBase64(base64_encode('audio-with-temperature'), 'audio/webm');

    $response = Prism::audio()
        ->using('openai', 'whisper-1')
        ->withInput($audioFile)
        ->withProviderOptions([
            'service_tier' => $serviceTier,
        ])
        ->asText();

    Http::assertSent(function (Request $request) use ($serviceTier): bool {
        $data = $request->data();

        expect($data[1]['name'])->toBe('service_tier');
        expect($data[1]['contents'])->toBe($serviceTier);

        return true;
    });
});
