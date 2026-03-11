<?php

use Prism\Prism\Concerns\GeneratesAudioFilename;

beforeEach(function (): void {
    $this->instance = new class
    {
        use GeneratesAudioFilename;

        public function generate(?string $mimeType): string
        {
            return $this->generateFilename($mimeType);
        }
    };
});

it('generates correct filename for flac audio', function (): void {
    expect($this->instance->generate('audio/flac'))->toBe('audio.flac');
});

it('generates correct filename for mpeg audio', function (): void {
    expect($this->instance->generate('audio/mpeg'))->toBe('audio.mp3');
});

it('generates correct filename for mp3 audio', function (): void {
    expect($this->instance->generate('audio/mp3'))->toBe('audio.mp3');
});

it('generates correct filename for mp4 audio', function (): void {
    expect($this->instance->generate('audio/mp4'))->toBe('audio.mp4');
});

it('generates correct filename for mpga audio', function (): void {
    expect($this->instance->generate('audio/mpga'))->toBe('audio.mpga');
});

it('generates correct filename for m4a audio', function (): void {
    expect($this->instance->generate('audio/m4a'))->toBe('audio.m4a');
});

it('generates correct filename for x-m4a audio', function (): void {
    expect($this->instance->generate('audio/x-m4a'))->toBe('audio.m4a');
});

it('generates correct filename for ogg audio', function (): void {
    expect($this->instance->generate('audio/ogg'))->toBe('audio.ogg');
});

it('generates correct filename for opus audio', function (): void {
    expect($this->instance->generate('audio/opus'))->toBe('audio.opus');
});

it('generates correct filename for wav audio', function (): void {
    expect($this->instance->generate('audio/wav'))->toBe('audio.wav');
});

it('generates correct filename for wave audio', function (): void {
    expect($this->instance->generate('audio/wave'))->toBe('audio.wav');
});

it('generates correct filename for webm audio', function (): void {
    expect($this->instance->generate('audio/webm'))->toBe('audio.webm');
});

it('defaults to mp3 for null mime type', function (): void {
    expect($this->instance->generate(null))->toBe('audio.mp3');
});

it('defaults to mp3 for unknown mime type', function (): void {
    expect($this->instance->generate('audio/unknown'))->toBe('audio.mp3');
});

it('defaults to mp3 for non-audio mime type', function (): void {
    expect($this->instance->generate('application/json'))->toBe('audio.mp3');
});
