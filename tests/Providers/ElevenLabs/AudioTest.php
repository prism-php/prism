<?php

declare(strict_types=1);

namespace Tests\Providers\ElevenLabs;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.elevenlabs.api_key', env('ELEVENLABS_API_KEY', 'fake-api-key'));
});

describe('Text-to-Speech', function (): void {
    it('can generate audio with basic model', function (): void {
        FixtureResponse::fakeResponseSequence(
            'text-to-speech/onwK4e9ZLuTAKqWW03F9',
            'elevenlabs/text-to-speech-basic',
            ['Content-Type' => 'audio/wav']
        );

        $response = Prism::audio()
            ->using('elevenlabs')
            ->withInput('Hello world!')
            ->withVoice('onwK4e9ZLuTAKqWW03F9')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'text-to-speech/onwK4e9ZLuTAKqWW03F9') &&
                $data['text'] === 'Hello world!';
        });
    });

    it('can generate audio with different response format', function (): void {
        FixtureResponse::fakeResponseSequence(
            'text-to-speech/onwK4e9ZLuTAKqWW03F9',
            'elevenlabs/text-to-speech-wav',
            ['Content-Type' => 'audio/wav']
        );

        $response = Prism::audio()
            ->using('elevenlabs')
            ->withInput('This is high quality audio')
            ->withVoice('onwK4e9ZLuTAKqWW03F9')
            ->withProviderOptions([
                'response_format' => 'wav',
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['text'] === 'This is high quality audio' &&
                $data['response_format'] === 'wav';
        });
    });

    it('can generate audio with speed control', function (): void {
        FixtureResponse::fakeResponseSequence(
            'text-to-speech/onwK4e9ZLuTAKqWW03F9',
            'elevenlabs/text-to-speech-speed',
            ['Content-Type' => 'audio/opus']
        );

        $response = Prism::audio()
            ->using('elevenlabs')
            ->withInput('Custom speed test')
            ->withVoice('onwK4e9ZLuTAKqWW03F9')
            ->withProviderOptions([
                'response_format' => 'opus',
                'speed' => 1.2,
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['text'] === 'Custom speed test' &&
                $data['response_format'] === 'opus' &&
                $data['voice_settings']['speed'] === 1.2;
        });
    });

    it('supports different voice options', function (): void {
        FixtureResponse::fakeResponseSequence(
            'text-to-speech/pNInz6obpgDQGcFmaJgB',
            'elevenlabs/text-to-speech-voice',
            ['Content-Type' => 'audio/mpeg']
        );

        $response = Prism::audio()
            ->using('elevenlabs')
            ->withInput('Testing echo voice')
            ->withVoice('pNInz6obpgDQGcFmaJgB')
            ->withProviderOptions([
                'response_format' => 'mp3',
            ])
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['response_format'] === 'mp3';
        });
    });
});

describe('Speech-to-Text', function (): void {
    it('can transcribe audio with base64 input', function (): void {
        FixtureResponse::fakeResponseSequence('v1/speech-to-text', 'elevenlabs/speech-to-text-base64');

        $audioFile = Audio::fromBase64(
            base64_encode(file_get_contents('tests/Fixtures/slightly-caffeinated-36.mp3')),
            'audio/mpeg'
        );

        $response = Prism::audio()
            ->using('elevenlabs', 'scribe_v1')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)->toContain("Maybe I don't sleep tonight");
        expect($response->additionalContent['language_code'])->toBe('eng');
        expect($response->additionalContent['language_probability'])->toBeNumeric();
        expect(count($response->additionalContent['words']))->toBeGreaterThan(10);
    });

    it('can transcribe audio from file path', function (): void {
        FixtureResponse::fakeResponseSequence('v1/speech-to-text', 'elevenlabs/speech-to-text-local-path');

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('elevenlabs', 'scribe_v1')
            ->withInput($audioFile)
            ->asText();

        expect($response->text)
            ->toBeString()
            ->not
            ->toBeEmpty();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text');
    });

    it('can transcribe with optional language code', function (): void {
        FixtureResponse::fakeResponseSequence('v1/speech-to-text', 'elevenlabs/speech-to-text-language-code');

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('elevenlabs', 'scribe_v1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'language_code' => 'fr',
            ])
            ->asText();

        expect($response->text)->toBeString()->not->toBeEmpty();

        Http::assertSent(
            fn (Request $request): bool => collect($request->data())
                ->where('name', 'language_code')
                ->sole()['contents'] === 'fr'
        );
    });

    it('can transcribe with speaker diarization', function (): void {
        FixtureResponse::fakeResponseSequence('v1/speech-to-text', 'elevenlabs/speech-to-text-diarization');

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('elevenlabs', 'scribe_v1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'num_speakers' => 2,
                'diarize' => true,
            ])
            ->asText();

        expect($response->text)->toBeString()->not->toBeEmpty();

        $words = collect($response->additionalContent['words']);
        $speakers = $words->unique('speaker_id')->pluck('speaker_id');

        expect($words->count())->toBeGreaterThan(0);
        expect($speakers)->toContain('speaker_0');
        expect($speakers)->toContain('speaker_1');
    });

    it('can transcribe with audio event tagging', function (): void {
        FixtureResponse::fakeResponseSequence('v1/speech-to-text', 'elevenlabs/speech-to-text-event-tagging');

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('elevenlabs', 'scribe_v1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'tag_audio_events' => true,
            ])
            ->asText();

        expect($response->text)->toBeString()->not->toBeEmpty();
        expect($response->additionalContent['words'][1]['type'])->toBe('spacing');
    });

    it('can transcribe with all optional parameters', function (): void {
        FixtureResponse::fakeResponseSequence('v1/speech-to-text', 'elevenlabs/speech-to-text-options');

        $audioFile = Audio::fromLocalPath('tests/Fixtures/slightly-caffeinated-36.mp3');

        $response = Prism::audio()
            ->using('elevenlabs', 'scribe_v1')
            ->withInput($audioFile)
            ->withProviderOptions([
                'language_code' => 'es',
                'num_speakers' => 2,
                'diarize' => true,
                'tag_audio_events' => true,
            ])
            ->asText();

        expect($response->text)
            ->toBeString()
            ->not
            ->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = collect($request->data())
                ->flatMap(fn ($content): array => [$content['name'] => $content['contents']])
                ->toArray();

            return $data['language_code'] === 'es'
                && $data['num_speakers'] === 2
                && $data['diarize'] === true
                && $data['tag_audio_events'] === true;
        });
    });
});
