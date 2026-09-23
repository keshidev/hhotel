<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PublicCaptchaService
{
    public function verify(?string $token, ?string $ipAddress = null): void
    {
        if (! config('contact.captcha.enabled')) {
            return;
        }

        if (! in_array(config('contact.captcha.provider'), ['recaptcha', 'recaptcha_v3'], true)) {
            throw ValidationException::withMessages(['captcha_token' => 'The configured security check is not supported.']);
        }

        $secret = trim((string) config('contact.captcha.secret'));
        if ($secret === '' || trim((string) $token) === '') {
            throw ValidationException::withMessages(['captcha_token' => 'Please complete the security check.']);
        }

        $response = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ipAddress,
        ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages(['captcha_token' => 'The security check could not be verified. Please try again.']);
        }

        $result = $response->json();
        $hostnames = config('contact.captcha.expected_hostnames', []);
        $hostname = strtolower((string) data_get($result, 'hostname', ''));
        $expectedAction = (string) config('contact.captcha.expected_action', 'contact_submit');

        $valid = data_get($result, 'success') === true
            && (float) data_get($result, 'score', 0) >= (float) config('contact.captcha.minimum_score', 0.5)
            && (string) data_get($result, 'action', '') === $expectedAction
            && ($hostnames === [] || in_array($hostname, array_map('strtolower', $hostnames), true));

        if (! $valid) {
            throw ValidationException::withMessages(['captcha_token' => 'The security check failed. Please refresh the page and try again.']);
        }
    }
}
