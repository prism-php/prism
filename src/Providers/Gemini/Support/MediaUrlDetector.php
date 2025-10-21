<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Support;

class MediaUrlDetector
{
    public static function shouldPassAsFileUri(string $url): bool
    {
        if (self::isYouTubeUrl($url)) {
            return true;
        }

        return self::isGeminiFileApiUri($url);
    }

    public static function isYouTubeUrl(string $url): bool
    {
        return (bool) preg_match('/^https?:\/\/(www\.)?(youtube\.com\/watch|youtu\.be\/)/i', $url);
    }

    public static function isGeminiFileApiUri(string $url): bool
    {
        return str_starts_with($url, 'https://generativelanguage.googleapis.com/v1beta/files/');
    }
}
