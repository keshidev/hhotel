<?php

namespace App\Support;

final class SensitiveDataRedactor
{
    public static function text(?string $value, int $limit = 1000): string
    {
        $text = (string) $value;

        $text = preg_replace(
            '/\b(?:sk|pk)_(?:test|live)_[A-Za-z0-9_-]+\b|\bwhsec_[A-Za-z0-9_-]+\b/i',
            '[redacted-secret]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            '[redacted-email]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/(?<!\d)(?:\+?63|0)9\d{9}(?!\d)/',
            '[redacted-phone]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/([?&](?:token|key|secret|signature|authorization)=)[^&\s]+/i',
            '$1[redacted]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/((?:password|token|secret|authorization|email|phone)\s*[:=]\s*)([^\s,;}]+)/i',
            '$1[redacted]',
            $text
        ) ?? $text;

        return mb_substr($text, 0, max(100, $limit));
    }
}
