<?php

declare(strict_types=1);

namespace Tests\Providers\ModelsLab;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;

beforeEach(function (): void {
    config()->set('prism.providers.modelslab.api_key', 'test-api-key');
});

describe('Image Generation Errors', function (): void {
    it('throws PrismRateLimitedException on 429 response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
            ], 429),
        ]);

        Prism::image()
            ->using(Provider::ModelsLab, 'flux')
            ->withPrompt('Test prompt')
            ->generate();
    })->throws(PrismRateLimitedException::class);

    it('throws PrismException on error status in response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
                'message' => 'Invalid prompt',
            ], 200),
        ]);

        Prism::image()
            ->using(Provider::ModelsLab, 'flux')
            ->withPrompt('Test prompt')
            ->generate();
    })->throws(PrismException::class, 'Invalid prompt');

    it('throws PrismException on async generation failure', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'processing',
                'id' => 12349,
                'fetch_result' => 'https://modelslab.com/api/v6/images/fetch/12349',
            ], 200),
            'modelslab.com/api/v6/images/fetch/12349' => Http::response([
                'status' => 'failed',
                'message' => 'Generation failed due to content policy',
            ], 200),
        ]);

        Prism::image()
            ->using(Provider::ModelsLab, 'flux')
            ->withPrompt('Test prompt')
            ->generate();
    })->throws(PrismException::class);

    it('throws PrismException on server error', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
                'message' => 'Internal server error',
            ], 500),
        ]);

        Prism::image()
            ->using(Provider::ModelsLab, 'flux')
            ->withPrompt('Test prompt')
            ->generate();
    })->throws(PrismException::class);
});

describe('Audio Generation Errors', function (): void {
    it('throws PrismException on TTS error status', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'error',
                'message' => 'Invalid voice ID',
            ], 200),
        ]);

        Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Test text')
            ->withVoice('invalid-voice')
            ->asAudio();
    })->throws(PrismException::class, 'Invalid voice ID');

    it('throws PrismException on STT error status', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'error',
                'message' => 'Unsupported audio format',
            ], 200),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('invalid-audio'), 'audio/unknown');

        Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();
    })->throws(PrismException::class, 'Unsupported audio format');

    it('throws PrismRateLimitedException on 429 for TTS', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
            ], 429),
        ]);

        Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Test text')
            ->withVoice('madison')
            ->asAudio();
    })->throws(PrismRateLimitedException::class);

    it('throws PrismRateLimitedException on 429 for STT', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/speech_to_text' => Http::response([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
            ], 429),
        ]);

        $audioFile = Audio::fromBase64(base64_encode('audio'), 'audio/mp3');

        Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput($audioFile)
            ->asText();
    })->throws(PrismRateLimitedException::class);

    it('throws PrismException when no audio URL in TTS response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/voice/text_to_speech' => Http::response([
                'status' => 'success',
                'output' => [],
            ], 200),
        ]);

        Prism::audio()
            ->using(Provider::ModelsLab, 'voice')
            ->withInput('Test text')
            ->withVoice('madison')
            ->asAudio();
    })->throws(PrismException::class, 'No audio URL in response');
});

describe('Error Message Handling', function (): void {
    it('extracts message from error response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
                'message' => 'Custom error message',
            ], 400),
        ]);

        try {
            Prism::image()
                ->using(Provider::ModelsLab, 'flux')
                ->withPrompt('Test')
                ->generate();
        } catch (PrismException $e) {
            expect($e->getMessage())->toContain('Custom error message');
        }
    });

    it('handles missing message in error response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
            ], 400),
        ]);

        try {
            Prism::image()
                ->using(Provider::ModelsLab, 'flux')
                ->withPrompt('Test')
                ->generate();
        } catch (PrismException $e) {
            expect($e->getMessage())->toContain('Unknown error');
        }
    });

    it('handles array validation error messages', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
                'message' => [
                    'scheduler' => [
                        'The scheduler field is required unless model id is in flux-kontext-dev.',
                    ],
                ],
            ], 200),
        ]);

        try {
            Prism::image()
                ->using(Provider::ModelsLab, 'flux')
                ->withPrompt('Test')
                ->generate();
        } catch (PrismException $e) {
            expect($e->getMessage())->toContain('scheduler field is required');
        }
    });

    it('handles messege typo in error response', function (): void {
        Http::fake([
            'modelslab.com/api/v6/images/text2img' => Http::response([
                'status' => 'error',
                'messege' => [
                    'scheduler' => [
                        'The scheduler field is required.',
                    ],
                ],
            ], 200),
        ]);

        try {
            Prism::image()
                ->using(Provider::ModelsLab, 'flux')
                ->withPrompt('Test')
                ->generate();
        } catch (PrismException $e) {
            expect($e->getMessage())->toContain('scheduler field is required');
        }
    });
});
