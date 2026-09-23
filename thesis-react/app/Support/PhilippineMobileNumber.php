<?php

namespace App\Support;

class PhilippineMobileNumber
{
    public static function normalize(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $compact = preg_replace('/[\s()-]/', '', trim($value));
        if (preg_match('/^(?:\+63|63|0)?(9[0-9]{9})$/D', $compact, $matches)) {
            return '+63'.$matches[1];
        }

        return trim($value);
    }
}
