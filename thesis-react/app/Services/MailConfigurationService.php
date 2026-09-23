<?php

namespace App\Services;

class MailConfigurationService
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $mailer = strtolower(trim((string) config('mail.default', 'smtp')));
        $host = trim((string) config("mail.mailers.{$mailer}.host", ''));
        $port = (int) config("mail.mailers.{$mailer}.port", 0);
        $encryption = strtolower(trim((string) config("mail.mailers.{$mailer}.encryption", '')));
        $username = trim((string) config("mail.mailers.{$mailer}.username", ''));
        $password = trim((string) config("mail.mailers.{$mailer}.password", ''));
        $fromAddress = trim((string) config('mail.from.address', ''));
        $fromName = trim((string) config('mail.from.name', ''));
        $issues = [];

        if ($mailer === 'smtp') {
            if ($host === '') {
                $issues[] = 'SMTP host is missing.';
            }

            if ($port < 1 || $port > 65535) {
                $issues[] = 'SMTP port must be between 1 and 65535.';
            }

            if (!in_array($encryption, ['', 'tls', 'ssl'], true)) {
                $issues[] = 'SMTP encryption must be TLS, SSL, or none.';
            }

            if ($username === '') {
                $issues[] = 'SMTP username is missing.';
            }

            if ($password === '') {
                $issues[] = 'SMTP password is missing.';
            }
        } elseif (in_array($mailer, ['array', 'log'], true)) {
            $issues[] = 'The active mailer does not deliver external email.';
        }

        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'The sender email address is missing or invalid.';
        }

        return [
            'configured' => $issues === [],
            'mailer' => $mailer !== '' ? $mailer : 'not configured',
            'host' => $mailer === 'smtp' ? ($host !== '' ? $host : 'not configured') : 'not applicable',
            'port' => $mailer === 'smtp' && $port > 0 ? $port : null,
            'encryption' => $mailer === 'smtp' ? ($encryption !== '' ? strtoupper($encryption) : 'None') : 'not applicable',
            'username' => $mailer === 'smtp' ? $this->maskIdentifier($username) : 'not applicable',
            'from_address' => $this->maskIdentifier($fromAddress),
            'from_name' => $fromName !== '' ? $fromName : 'not configured',
            'queue_connection' => (string) config('queue.default', 'sync'),
            'issues' => $issues,
        ];
    }

    public function maskIdentifier(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'not configured';
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            [$local, $domain] = explode('@', $value, 2);
            $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

            return $visible . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
        }

        if (mb_strlen($value) <= 4) {
            return mb_substr($value, 0, 1) . '***';
        }

        return mb_substr($value, 0, 2) . '***' . mb_substr($value, -2);
    }
}
