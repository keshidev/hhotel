<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

final class StaffAccountRules
{
    public static function password(): Password
    {
        return Password::min(12)->letters()->mixedCase()->numbers()->symbols();
    }

    public static function name(): array
    {
        return ['required', 'string', 'min:2', 'max:255', "regex:/^[\\pL\\s.'\\x{2019}-]+$/u"];
    }

    public static function phone(): array
    {
        return ['nullable', 'string', 'regex:/^09\\d{9}$/'];
    }
}
