<?php

namespace App\Providers;

use App\Support\PhilippineMobileNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('staff-login', function (Request $request) {
            return Limit::perMinute(10)
                ->by('staff-login|ip:'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    $retryAfterSeconds = max(1, (int) ($headers['Retry-After'] ?? 60));

                    return response()->json([
                        'success' => false,
                        'message' => 'Too many login attempts. Please wait before trying again.',
                        'retry_after_seconds' => $retryAfterSeconds,
                    ], 429, $headers);
                });
        });

        RateLimiter::for('client-bookings', function (Request $request) {
            $maxPerDay = max(1, (int) config('bookings.max_per_ip_per_day', 20));
            $maxPerMinute = max(1, (int) config('bookings.max_per_ip_per_minute', 5));

            $email = $this->identityHash($request->input('guest_email'));
            $phone = $this->identityHash(PhilippineMobileNumber::normalize($request->input('guest_phone')));

            return [
                Limit::perMinute($maxPerMinute)
                    ->by('ip:' . $request->ip())
                    ->response(function () use ($maxPerMinute) {
                        return response()->json([
                            'success' => false,
                            'message' => "Too many reservation attempts from this IP address. Limit is {$maxPerMinute} per minute.",
                        ], 429);
                    }),

                Limit::perDay($maxPerDay)
                    ->by('ip:' . $request->ip())
                    ->response(function () use ($maxPerDay) {
                        return response()->json([
                            'success' => false,
                            'message' => "Too many reservation attempts from this IP address. Limit is {$maxPerDay} per day.",
                        ], 429);
                    }),

                Limit::perDay($maxPerDay)
                    ->by('email:' . $email)
                    ->response(function () use ($maxPerDay) {
                        return response()->json([
                            'success' => false,
                            'message' => "Too many reservation attempts for this email. Limit is {$maxPerDay} per day.",
                        ], 429);
                    }),

                Limit::perDay($maxPerDay)
                    ->by('phone:' . $phone)
                    ->response(function () use ($maxPerDay) {
                        return response()->json([
                            'success' => false,
                            'message' => "Too many reservation attempts for this phone number. Limit is {$maxPerDay} per day.",
                        ], 429);
                    }),
            ];
        });

        RateLimiter::for('manual-gcash-prepare', function (Request $request) {
            $bookingId = (int) $request->route('bookingId');
            $maxPerBooking = max(1, (int) config('payment.manual_gcash.prepare_attempts_per_minute', 10));
            $maxPerIp = max($maxPerBooking, (int) config('payment.manual_gcash.prepare_ip_attempts_per_minute', 60));
            $tooManyAttempts = function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many payment page requests. Please wait briefly, then try again.',
                ], 429, $headers);
            };

            return [
                Limit::perMinute($maxPerBooking)
                    ->by("booking:{$bookingId}|ip:{$request->ip()}")
                    ->response($tooManyAttempts),
                Limit::perMinute($maxPerIp)
                    ->by('ip:' . $request->ip())
                    ->response($tooManyAttempts),
            ];
        });

        RateLimiter::for('manual-gcash-proof', function (Request $request) {
            $bookingId = (int) $request->route('bookingId');
            $cookieName = (string) config('bookings.payment_access_cookie_prefix', 'hotel_payment_access_').$bookingId;
            $accessToken = trim((string) $request->cookie($cookieName, ''));
            $sessionKey = $accessToken !== '' ? hash('sha256', $accessToken) : 'missing';
            $maxPerSession = max(3, (int) config('payment.manual_gcash.proof_requests_per_minute', 10));
            $maxPerIp = max($maxPerSession, (int) config('payment.manual_gcash.proof_ip_requests_per_minute', 60));
            $response = $this->manualGcashRateLimitResponse(
                'Too many payment proof requests. Please wait briefly before trying again.'
            );

            return [
                Limit::perMinute($maxPerSession)
                    ->by("manual-gcash-proof|booking:{$bookingId}|session:{$sessionKey}")
                    ->response($response),
                Limit::perMinute($maxPerIp)
                    ->by('manual-gcash-proof|ip:'.$request->ip())
                    ->response($response),
            ];
        });

        RateLimiter::for('manual-gcash-resume', function (Request $request) {
            $bookingId = (int) $request->route('bookingId');
            $email = $this->identityHash($request->input('email'));
            $maxPerIdentity = max(3, (int) config('payment.manual_gcash.resume_requests_per_minute', 10));
            $maxPerIp = max($maxPerIdentity, (int) config('payment.manual_gcash.resume_ip_requests_per_minute', 60));
            $response = $this->manualGcashRateLimitResponse(
                'Too many payment correction requests. Please wait briefly before trying again.'
            );

            return [
                Limit::perMinute($maxPerIdentity)
                    ->by("manual-gcash-resume|booking:{$bookingId}|email:{$email}|ip:{$request->ip()}")
                    ->response($response),
                Limit::perMinute($maxPerIp)
                    ->by('manual-gcash-resume|ip:'.$request->ip())
                    ->response($response),
            ];
        });

        RateLimiter::for('manual-gcash-resume-link', function (Request $request) {
            $token = hash('sha256', trim((string) $request->route('token')));
            $maxPerToken = max(3, (int) config('payment.manual_gcash.resume_link_requests_per_minute', 10));
            $maxPerIp = max($maxPerToken, (int) config('payment.manual_gcash.resume_ip_requests_per_minute', 60));
            $response = $this->manualGcashRateLimitResponse(
                'Too many payment correction link requests. Please wait briefly before trying again.'
            );

            return [
                Limit::perMinute($maxPerToken)
                    ->by("manual-gcash-resume-link|token:{$token}|ip:{$request->ip()}")
                    ->response($response),
                Limit::perMinute($maxPerIp)
                    ->by('manual-gcash-resume-link|ip:'.$request->ip())
                    ->response($response),
            ];
        });

        RateLimiter::for('password-recovery-request', function (Request $request) {
            $email = $this->identityHash($request->input('email'));
            $response = function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many password recovery requests. Please wait before trying again.',
                ], 429, $headers);
            };

            return [
                Limit::perMinute(5)->by('ip:' . $request->ip())->response($response),
                Limit::perHour(5)->by('email:' . $email)->response($response),
            ];
        });

        RateLimiter::for('password-recovery-reset', function (Request $request) {
            $email = $this->identityHash($request->input('email'));
            $response = function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many password reset attempts. Please wait before trying again.',
                ], 429, $headers);
            };

            return [
                Limit::perMinute(10)->by('ip:' . $request->ip())->response($response),
                Limit::perMinute(5)->by('email:' . $email)->response($response),
            ];
        });

        RateLimiter::for('contact-inquiries', function (Request $request) {
            $email = $this->identityHash($request->input('email'));
            $response = function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many messages were submitted. Please wait before trying again.',
                ], 429, $headers);
            };

            return [
                Limit::perMinute((int) config('contact.rate_limit_per_minute', 5))
                    ->by('contact|ip:'.$request->ip())
                    ->response($response),
                Limit::perHour((int) config('contact.rate_limit_per_hour', 10))
                    ->by('contact|email:'.$email)
                    ->response($response),
            ];
        });

        RateLimiter::for('feedback-view', function (Request $request) {
            $token = hash('sha256', trim((string) $request->route('token')));
            $response = $this->feedbackRateLimitResponse('Too many feedback link requests. Please wait briefly and try again.');

            return [
                Limit::perMinute((int) config('feedback.view_attempts_per_minute', 30))
                    ->by('feedback-view|token:'.$token.'|ip:'.$request->ip())
                    ->response($response),
                Limit::perMinute((int) config('feedback.view_ip_attempts_per_minute', 120))
                    ->by('feedback-view|ip:'.$request->ip())
                    ->response($response),
            ];
        });

        RateLimiter::for('feedback-submit', function (Request $request) {
            $token = hash('sha256', trim((string) $request->route('token')));
            $response = $this->feedbackRateLimitResponse('Too many feedback submission attempts. Please wait briefly and try again.');

            return [
                Limit::perMinute((int) config('feedback.submit_attempts_per_minute', 5))
                    ->by('feedback-submit|token:'.$token)
                    ->response($response),
                Limit::perMinute((int) config('feedback.submit_ip_attempts_per_minute', 30))
                    ->by('feedback-submit|ip:'.$request->ip())
                    ->response($response),
            ];
        });

        RateLimiter::for('feedback-public', function (Request $request) {
            return Limit::perMinute((int) config('feedback.public_reads_per_minute', 120))
                ->by('feedback-public|ip:'.$request->ip())
                ->response($this->feedbackRateLimitResponse('Too many review requests. Please wait briefly and try again.'));
        });
    }

    private function identityHash(mixed $value): string
    {
        return hash('sha256', is_string($value) ? strtolower(trim($value)) : '');
    }

    private function manualGcashRateLimitResponse(string $message): callable
    {
        return function (Request $request, array $headers) use ($message) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'retry_after_seconds' => max(1, (int) ($headers['Retry-After'] ?? 60)),
            ], 429, $headers);
        };
    }

    private function feedbackRateLimitResponse(string $message): callable
    {
        return function (Request $request, array $headers) use ($message) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'retry_after_seconds' => max(1, (int) ($headers['Retry-After'] ?? 60)),
            ], 429, $headers);
        };
    }
}
