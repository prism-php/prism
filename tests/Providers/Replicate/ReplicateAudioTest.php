<?php

declare(strict_types=1);

namespace Tests\Providers\Replicate;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.replicate.api_key', env('REPLICATE_API_KEY', 'r8_test1234'));
    config()->set('prism.providers.replicate.polling_interval', 10);
    config()->set('prism.providers.replicate.max_wait_time', 10);
});

describe('Text-to-Speech for Replicate', function (): void {
    it('can generate audio from text', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/text-to-speech-basic-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/text-to-speech-basic-2.json'), true);
        $predictionId = $createResponse['id'];
        $audioUrl = $completedResponse['output'];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $audioUrl => Http::response('fake-audio-content', 200),
        ]);

        $response = Prism::audio()
            ->using('replicate', 'jaaari/kokoro-82m')
            ->withInput('Hello world!')
            ->withVoice('af_alloy')
            ->asAudio();

        expect($response->audio)->not->toBeNull()
            ->and($response->audio->hasBase64())->toBeTrue()
            ->and($response->audio->base64)->not->toBeEmpty();
    });

    it('can generate audio with different voice', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/text-to-speech-different-voice-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/text-to-speech-different-voice-2.json'), true);
        $predictionId = $createResponse['id'];
        $audioUrl = $completedResponse['output'];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $audioUrl => Http::response('fake-audio-content', 200),
        ]);

        $response = Prism::audio()
            ->using('replicate', 'jaaari/kokoro-82m')
            ->withInput('Testing echo voice')
            ->withVoice('af_bella')
            ->asAudio();

        expect($response->audio)->not->toBeNull()
            ->and($response->audio->hasBase64())->toBeTrue();
    });

    it('can generate audio with provider options', function (): void {
        $createResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/text-to-speech-long-text-1.json'), true);
        $completedResponse = json_decode(file_get_contents(__DIR__.'/../../Fixtures/replicate/text-to-speech-long-text-2.json'), true);
        $predictionId = $createResponse['id'];
        $audioUrl = $completedResponse['output'];

        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response($createResponse, 201),
            "https://api.replicate.com/v1/predictions/{$predictionId}" => Http::response($completedResponse, 200),
            $audioUrl => Http::response('fake-audio-content', 200),
        ]);

        Prism::audio()
            ->using('replicate', 'jaaari/kokoro-82m')
            ->withInput('This is a longer piece of text to test the text-to-speech capabilities.')
            ->withVoice('af_sky')
            ->withProviderOptions([
                'voice' => 'af_sky',
                'speed' => 1.0,
            ])
            ->asAudio();

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), 'predictions')) {
                return false;
            }

            $body = json_decode((string) $request->body(), true);

            return isset($body['input']['text'])
                && isset($body['input']['voice'])
                && $body['input']['voice'] === 'af_sky';
        });
    });
});

describe('Speech-to-Text for Replicate', function (): void {
    it('can transcribe WAV audio file from data URL', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/speech-to-text-wav');

        $audioPath = __DIR__.'/../../Fixtures/sample-audio.wav';
        $audioContent = file_get_contents($audioPath);
        $base64Audio = base64_encode($audioContent);
        $dataUrl = 'data:audio/wav;base64,'.$base64Audio;

        $response = Prism::audio()
            ->using('replicate', 'vaibhavs10/incredibly-fast-whisper')
            ->withInput(new Audio(url: $dataUrl))
            ->asText();

        expect($response->text)->toContain('Kids')
            ->and($response->additionalContent['metrics']['predict_time'])->toBeGreaterThan(0);
    });

    it('can transcribe MP3 audio file from data URL', function (): void {
        FixtureResponse::fakeResponseSequence('*', 'replicate/speech-to-text-mp3');

        $audioPath = __DIR__.'/../../Fixtures/slightly-caffeinated-36.mp3';
        $audioContent = file_get_contents($audioPath);
        $base64Audio = base64_encode($audioContent);
        $dataUrl = 'data:audio/mpeg;base64,'.$base64Audio;

        $response = Prism::audio()
            ->using('replicate', 'vaibhavs10/incredibly-fast-whisper')
            ->withInput(new Audio(url: $dataUrl))
            ->asText();

        expect($response->text)->not->toBeEmpty()
            ->and($response->additionalContent['metrics']['predict_time'])->toBeGreaterThan(0);
    });
});
