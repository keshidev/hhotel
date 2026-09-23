<?php

namespace App\Services;

class GuestPrivacyService
{
    public function maskedName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return 'Verified Guest';
        }

        return collect($parts)
            ->take(3)
            ->map(function (string $part) {
                $initial = mb_strtoupper(mb_substr($part, 0, 1));

                return $initial !== '' ? $initial . '.' : '';
            })
            ->filter()
            ->implode(' ');
    }
}
