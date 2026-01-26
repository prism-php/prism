<?php

declare(strict_types=1);

namespace Tests\Providers\Gemini;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.gemini.api_key', env('GEMINI_API_KEY', 'test-api-key'));
});

describe('Text-to-Speech', function (): void {
    it('can generate audio with gemini-2.5-flash-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-flash-preview-tts:generateContent',
            'gemini/tts-flash-1'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-flash-preview-tts')
            ->withInput('Hello, world!')
            ->withVoice('Kore')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent' &&
                $data['model'] === 'gemini-2.5-flash-preview-tts' &&
                $data['contents'][0]['parts'][0]['text'] === 'Hello, world!';
        });
    });

    it('can generate audio with gemini-2.5-pro-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-pro-preview-tts:generateContent',
            'gemini/tts-pro-1'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-pro-preview-tts')
            ->withInput('Hello, world!')
            ->withVoice('Kore')
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();
        expect($response->audio->base64)->not->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro-preview-tts:generateContent' &&
                $data['model'] === 'gemini-2.5-pro-preview-tts' &&
                $data['contents'][0]['parts'][0]['text'] === 'Hello, world!';
        });
    });

    it('supports different voice options for gemini-2.5-pro-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-pro-preview-tts:generateContent',
            'gemini/tts-pro-voice-option'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-pro-preview-tts')
            ->withInput('Hello, world!')
            ->withVoice('Enceladus')
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Enceladus';
        });
    });

    it('supports different voice options for gemini-2.5-flash-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-flash-preview-tts:generateContent',
            'gemini/tts-flash-voice-option'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-flash-preview-tts')
            ->withInput('Hello, world!')
            ->withVoice('Enceladus')
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Enceladus';
        });
    });

    it('supports multi-speaker voice configuration for gemini-2.5-pro-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-pro-preview-tts:generateContent',
            'gemini/tts-pro-voice-option'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-pro-preview-tts')
            ->withInput('TTS the following conversation between Joe and Jane:
                    Joe: Hows it going today Jane?
                    Jane: Not too bad, how about you?')
            ->withVoice('Enceladus')
            ->withProviderOptions([
                'multiSpeaker' => [
                    [
                        'speaker' => 'Joe',
                        'voiceName' => 'Kore',
                    ],
                    [
                        'speaker' => 'Jane',
                        'voiceName' => 'Puck',
                    ],
                ],
            ])
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            if (! isset($data['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'])) {
                return false;
            }

            $speakerConfigs = $data['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'];

            if (count($speakerConfigs) !== 2) {
                return false;
            }

            $joe = collect($speakerConfigs)->firstWhere('speaker', 'Joe');
            if (! $joe || $joe['voiceConfig']['prebuiltVoiceConfig']['voiceName'] !== 'Kore') {
                return false;
            }

            $jane = collect($speakerConfigs)->firstWhere('speaker', 'Jane');
            if (! $jane || $jane['voiceConfig']['prebuiltVoiceConfig']['voiceName'] !== 'Puck') {
                return false;
            }

            return true;
        });
    });

    it('supports multi-speaker voice configuration for gemini-2.5-flash-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-flash-preview-tts:generateContent',
            'gemini/tts-flash-voice-option'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-flash-preview-tts')
            ->withInput('TTS the following conversation between Joe and Jane:
                    Joe: Hows it going today Jane?
                    Jane: Not too bad, how about you?')
            ->withVoice('Enceladus')
            ->withProviderOptions([
                'multiSpeaker' => [
                    [
                        'speaker' => 'Joe',
                        'voiceName' => 'Kore',
                    ],
                    [
                        'speaker' => 'Jane',
                        'voiceName' => 'Puck',
                    ],
                ],
            ])
            ->asAudio();

        expect($response->audio)->not->toBeNull();
        expect($response->audio->hasBase64())->toBeTrue();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            if (! isset($data['generationConfig']['speechConfig']['multiSpeakerVoiceConfig'])) {
                return false;
            }

            $speakerConfigs = $data['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'];

            if (count($speakerConfigs) !== 2) {
                return false;
            }

            $joe = collect($speakerConfigs)->firstWhere('speaker', 'Joe');
            if (! $joe || $joe['voiceConfig']['prebuiltVoiceConfig']['voiceName'] !== 'Kore') {
                return false;
            }

            $jane = collect($speakerConfigs)->firstWhere('speaker', 'Jane');
            if (! $jane || $jane['voiceConfig']['prebuiltVoiceConfig']['voiceName'] !== 'Puck') {
                return false;
            }

            return true;
        });
    });

    it('prioritizes multi-speaker config over single voice for gemini-2.5-pro-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-pro-preview-tts:generateContent',
            'gemini/tts-pro-multi-speaker'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-pro-preview-tts')
            ->withInput('Conversation test')
            ->withVoice('Enceladus')
            ->withProviderOptions([
                'multiSpeaker' => [
                    [
                        'speaker' => 'Speaker1',
                        'voiceName' => 'Kore',
                    ],
                ],
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return isset($data['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']) &&
                   ! isset($data['generationConfig']['speechConfig']['voiceConfig']);
        });
    });

    it('prioritizes multi-speaker config over single voice for gemini-2.5-flash-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-flash-preview-tts:generateContent',
            'gemini/tts-flash-multi-speaker'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-flash-preview-tts')
            ->withInput('Conversation test')
            ->withVoice('Enceladus')
            ->withProviderOptions([
                'multiSpeaker' => [
                    [
                        'speaker' => 'Speaker1',
                        'voiceName' => 'Kore',
                    ],
                ],
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return isset($data['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']) &&
                   ! isset($data['generationConfig']['speechConfig']['voiceConfig']);
        });
    });

    it('handles invalid multi-speaker configurations gracefully for gemini-2.5-pro-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-pro-preview-tts:generateContent',
            'gemini/tts-pro-multi-speaker'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-pro-preview-tts')
            ->withInput('Test')
            ->withVoice('Enceladus')
            ->withProviderOptions([
                'multiSpeaker' => [
                    ['speaker' => 'Joe'],
                    ['voiceName' => 'Kore'],
                ],
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return isset($data['generationConfig']['speechConfig']['voiceConfig']) &&
                   $data['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Enceladus';
        });
    });

    it('handles invalid multi-speaker configurations gracefully for gemini-2.5-flash-preview-tts model', function (): void {
        FixtureResponse::fakeResponseSequence(
            '/gemini-2.5-flash-preview-tts:generateContent',
            'gemini/tts-flash-multi-speaker'
        );

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-flash-preview-tts')
            ->withInput('Test')
            ->withVoice('Enceladus')
            ->withProviderOptions([
                'multiSpeaker' => [
                    ['speaker' => 'Joe'],
                    ['voiceName' => 'Kore'],
                ],
            ])
            ->asAudio();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return isset($data['generationConfig']['speechConfig']['voiceConfig']) &&
                   $data['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Enceladus';
        });
    });
});

describe('GeneratedAudio Value Object', function (): void {
    it('can check if audio has base64 data', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro-preview-tts:generateContent' => Http::response(
                [
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'inlineData' => [
                                            'mimeType' => 'audio/wav',
                                            'data' => 'UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $response = Prism::audio()
            ->using(Provider::Gemini, 'gemini-2.5-pro-preview-tts')
            ->withInput('Test audio generation')
            ->withVoice('Enceladus')
            ->asAudio();

        expect($response->audio->hasBase64())->toBeTrue();
    });
});
