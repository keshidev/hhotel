<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseReadinessService
{
    public function __construct(
        private BackupVerificationService $backupVerification,
        private SchedulerHeartbeatService $schedulerHeartbeat,
        private PaymentProviderService $paymentProvider,
        private ManualGcashOperationalHealthService $manualGcashHealth
    ) {}

    /**
     * @return array{ready: bool, checks: array<int, array{name: string, passed: bool, detail: string}>}
     */
    public function status(bool $requireProduction = true): array
    {
        $environment = (string) config('app.env');
        $backup = $this->backupVerification->status();
        $scheduler = $this->schedulerHeartbeat->status();
        $providerIssues = $this->paymentProvider->operationalIssues();
        $manualHealth = $this->paymentProvider->uses(PaymentProviderService::MANUAL_GCASH)
            ? $this->manualGcashHealth->status()
            : null;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $staffTokenExpiration = (int) config('sanctum.expiration', 0);
        $securityHeadersEnabled = (bool) config('security.headers_enabled', false);
        $contentSecurityPolicy = trim((string) config('security.content_security_policy', ''));
        $corsOrigins = array_values(array_filter((array) config('cors.allowed_origins', [])));
        $strictCorsRequired = in_array($environment, ['production', 'staging'], true);
        $corsOriginsValid = $corsOrigins !== [] && collect($corsOrigins)->every(function ($origin) use ($strictCorsRequired) {
            if (! is_string($origin) || filter_var($origin, FILTER_VALIDATE_URL) === false) {
                return false;
            }

            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));

            return ! $strictCorsRequired
                || ($scheme === 'https' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true));
        });

        $checks = [
            $this->check('Environment', ! $requireProduction || $environment === 'production', $environment),
            $this->check('Debug mode', ! (bool) config('app.debug'), config('app.debug') ? 'enabled' : 'disabled'),
            $this->check('Application URL', $this->isHttpsUrl(config('app.url')), 'HTTPS required'),
            $this->check('Frontend URL', $this->isHttpsUrl(config('app.frontend_url')), 'HTTPS required'),
            $this->check('Application key', mb_strlen((string) config('app.key')) >= 32, 'configured key required'),
            $this->check('Session secure cookie', (bool) config('session.secure'), 'must be enabled'),
            $this->check('Session HttpOnly cookie', (bool) config('session.http_only'), 'must be enabled'),
            $this->check(
                'Session SameSite',
                in_array(config('session.same_site'), ['lax', 'strict'], true),
                (string) config('session.same_site')
            ),
            $this->check(
                'Staff token lifetime',
                $staffTokenExpiration >= 15 && $staffTokenExpiration <= 480,
                $staffTokenExpiration > 0 ? "{$staffTokenExpiration} minutes" : 'expiration required'
            ),
            $this->check(
                'Security headers',
                $securityHeadersEnabled && $contentSecurityPolicy !== '',
                'enabled with Content Security Policy required'
            ),
            $this->check(
                'CORS origins',
                $corsOriginsValid,
                $corsOrigins === [] ? 'at least one frontend origin required' : implode(', ', $corsOrigins)
            ),
            $this->check('Queue connection', ! in_array(config('queue.default'), ['sync', 'null'], true), (string) config('queue.default')),
            $this->check('Cache store', ! in_array(config('cache.default'), ['array', 'null'], true), (string) config('cache.default')),
            $this->check('Mail transport', ! in_array(config('mail.default'), ['array', 'log'], true), (string) config('mail.default')),
            $this->check(
                'Booking CAPTCHA',
                (bool) config('bookings.captcha.enabled') && trim((string) config('bookings.captcha.secret')) !== '',
                'enabled with secret required'
            ),
            $this->check(
                'Contact CAPTCHA',
                (bool) config('contact.captcha.enabled')
                    && in_array(config('contact.captcha.provider'), ['recaptcha', 'recaptcha_v3'], true)
                    && trim((string) config('contact.captcha.secret')) !== '',
                'supported provider and secret required'
            ),
            $this->check('Payment operations', $providerIssues === [], $providerIssues === [] ? 'valid' : implode(', ', $providerIssues)),
            $this->check('Scheduler heartbeat', (bool) $scheduler['healthy'], (string) $scheduler['status']),
            $this->check('Failed queue jobs', $failedJobs === 0, $failedJobs === null ? 'table missing' : (string) $failedJobs),
            $this->check('Backup and restore evidence', (bool) $backup['healthy'], $backup['reason']),
        ];

        if (is_array($manualHealth)) {
            $checks[] = $this->check(
                'Manual GCash operations',
                (bool) $manualHealth['healthy'],
                $manualHealth['healthy'] ? 'healthy' : implode(', ', $manualHealth['issues'])
            );
        }

        return [
            'ready' => collect($checks)->every(fn (array $check) => $check['passed']),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, passed: bool, detail: string}
     */
    private function check(string $name, bool $passed, string $detail): array
    {
        return compact('name', 'passed', 'detail');
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
