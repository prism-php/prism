<?php

declare(strict_types=1);

namespace Tests\Providers\Groq;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.groq.api_key', env('GROQ_API_KEY', 'fake-api-key'));
});

describe('Text-to-Speech', function (): void {
    it('can generate audio with basic model', function (): void {
        FixtureResponse::fakeResponseSequence(
            'audio/speech',
            'groq/text-to-speech-basic',
            ['Content-Type' => 'audio/mpeg']
        );

        $response = Prism::audio()
            ->using('groq', 'tts-1')
            ->withInput('Hello world!')
            ->withVoice('alloy')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'audio/speech') &&
                   $data['model'] === 'tts-1' &&
                   $data['input'] === 'Hello world!';
        });
    });

    it('can generate audio with different response format', function (): void {
        FixtureResponse::fakeResponseSequence(
            'audio/speech',
            'groq/text-to-speech-wav',
            ['Content-Type' => 'audio/wav']
        );

        $response = Prism::audio()
            ->using('groq', 'tts-1')
            ->withInput('This is high quality audio')
            ->withVoice('nova')
            ->withProviderOptions([
                'response_format' => 'wav',
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['model'] === 'tts-1' &&
                   $data['input'] === 'This is high quality audio' &&
                   $data['voice'] === 'nova' &&
                   $data['response_format'] === 'wav';
        });
    });

    it('can generate audio with speed control', function (): void {
        FixtureResponse::fakeResponseSequence(
            'audio/speech',
            'groq/text-to-speech-speed',
            ['Content-Type' => 'audio/opus']
        );

        $response = Prism::audio()
            ->using('groq', 'tts-1')
            ->withInput('Custom speed test')
            ->withVoice('alloy')
            ->withProviderOptions([
                'response_format' => 'opus',
                'speed' => 1.2,
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['model'] === 'tts-1' &&
                   $data['input'] === 'Custom speed test' &&
                   $data['voice'] === 'alloy' &&
                   $data['response_format'] === 'opus' &&
                   $data['speed'] === 1.2;
        });
    });

    it('supports different voice options', function (): void {
        FixtureResponse::fakeResponseSequence(
            'audio/speech',
            'groq/text-to-speech-voice',
            ['Content-Type' => 'audio/mpeg']
        );

        $response = Prism::audio()
            ->using('groq', 'tts-1')
            ->withInput('Testing echo voice')
            ->withVoice('echo')
            ->withProviderOptions([
                'response_format' => 'mp3',
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['voice'] === 'echo' &&
                   $data['response_format'] === 'mp3';
        });
    });
});

describe('Speech-to-Text', function (): void {
    it('can transcribe audio with whisper-large-v3-turbo model - base64 - json', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'groq/audio-from-base64'
        );

        $audioFile = Audio::fromBase64(
            base64_encode(file_get_contents('tests/Fixtures/slightly-caffeinated-36.mp3'))
        );

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
            ->withInput($audioFile)
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio with whisper-large-v3-turbo model - from path - json', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'groq/audio-from-path'
        );

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
            ->withInput($audioFile)
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio with whisper-large-v3-turbo model - from path - text', function (): void {
        FixtureResponse::fakeResponseSequence(
            'v1/audio/transcriptions',
            'groq/audio-from-path-text'
        );

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
            ->withInput($audioFile)
            ->withProviderOptions([
                'response_format' => 'text',
            ])
            ->withClientOptions(['timeout' => 9999])
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toContain("So I'd love to hear about your experience here");
    });

    it('can transcribe audio', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'groq/speech-to-text-basic');

        $audioFile = Audio::fromBase64(base64_encode('fake-audio-content'), 'audio/mp3');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->not->toBeNull();
        expect($response->text)->not->toBeEmpty();
        expect($response->text)->toBe('Hello, this is a test transcription.');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'audio/transcriptions'));
    });

    it('can transcribe with verbose json response format', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'groq/speech-to-text-verbose');

        $audioFile = Audio::fromBase64(base64_encode('detailed-audio-content'), 'audio/m4a');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
            ->withInput($audioFile)
            ->withProviderOptions([
                'response_format' => 'verbose_json',
            ])
            ->asText();

        expect($response->text)->toBe('The quick brown fox jumps over the lazy dog.');
        expect($response->usage)->not->toBeNull();
        expect($response->usage->completionTokens)->toBe(12);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'audio/transcriptions'));
    });

    it('includes usage information when available', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'groq/speech-to-text-usage');

        $audioFile = Audio::fromBase64(base64_encode('usage-test-audio'), 'audio/flac');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3')
            ->withInput($audioFile)
            ->asText();

        expect($response->usage)->not->toBeNull();
        expect($response->usage->promptTokens)->toBe(5);
        expect($response->usage->completionTokens)->toBe(8);
    });

    it('handles transcription without usage information', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'groq/speech-to-text-simple');

        $audioFile = Audio::fromBase64(base64_encode('simple-audio'), 'audio/ogg');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toBe('Simple transcription without usage data.');
        expect($response->usage)->toBeNull();
    });
});

describe('Speech-to-Text Response', function (): void {
    it('can handle complex transcription responses', function (): void {
        FixtureResponse::fakeResponseSequence('audio/transcriptions', 'groq/speech-to-text-complex');

        $audioFile = Audio::fromBase64(base64_encode('complex-audio'), 'audio/mp3');

        $response = Prism::audio()
            ->using('groq', 'whisper-large-v3-turbo')
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
