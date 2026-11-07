<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait GeneratesAudioFilename
{
    protected function generateFilename(?string $mimeType): string
    {
        // MediaRecorder attaches codec parameters ("audio/webm;codecs=opus"),
        // so match on the bare, lowercased type.
        $mimeType = $mimeType === null
            ? null
            : strtolower(trim(explode(';', $mimeType)[0]));

        $extension = match ($mimeType) {
            'audio/flac' => 'flac',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'video/mp4' => 'mp4',
            'audio/mpga' => 'mpga',
            'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/ogg', 'video/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'audio/wav', 'audio/wave' => 'wav',
            'audio/webm', 'video/webm' => 'webm',
            default => 'mp3',
        };

        return "audio.{$extension}";
    }
}
