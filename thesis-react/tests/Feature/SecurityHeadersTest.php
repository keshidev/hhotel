<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_include_browser_security_headers(): void
    {
        config([
            'security.headers_enabled' => true,
            'security.content_security_policy' => "default-src 'self'",
        ]);

        $this->getJson('/api/system/scheduler-health')
            ->assertHeader('Content-Security-Policy', "default-src 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    public function test_production_https_responses_include_hsts(): void
    {
        config([
            'app.env' => 'production',
            'security.headers_enabled' => true,
            'security.hsts_max_age' => 31536000,
        ]);

        $this->withServerVariables(['HTTPS' => 'on'])
            ->getJson('/api/system/scheduler-health')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
