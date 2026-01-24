<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait GeneratesAudioFilename
{
    protected function generateFilename(?string $mimeType): string
    {
        $extension = match ($mimeType) {
            'audio/flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4' => 'mp4',
            'audio/mpga' => 'mpga',
            'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'audio/wav', 'audio/wave' => 'wav',
            'audio/webm' => 'webm',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }
}
