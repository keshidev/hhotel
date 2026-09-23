<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaffTokenSecurityTest extends TestCase
{
    public function test_staff_tokens_have_a_bounded_default_lifetime(): void
    {
        $this->assertSame(480, config('sanctum.expiration'));
    }

    public function test_frontend_keeps_staff_credentials_out_of_persistent_storage(): void
    {
        $source = file_get_contents(base_path('../react/src/services/authStorage.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('sessionStorage.setItem(config.token, token)', $source);
        $this->assertStringNotContainsString('localStorage.setItem(config.token, token)', $source);
        $this->assertStringNotContainsString('localStorage.setItem(config.user, JSON.stringify(user))', $source);
    }

    public function test_frontend_requires_reauthentication_for_creation_and_signs_out_after_password_change(): void
    {
        $userManagement = file_get_contents(base_path('../react/src/pages/admin/UserManagement.jsx'));
        $receptionistSettings = file_get_contents(base_path('../react/src/pages/receptionist/Settings.jsx'));
        $toastUtility = file_get_contents(base_path('../react/src/utils/showToast.js'));

        $this->assertIsString($userManagement);
        $this->assertIsString($receptionistSettings);
        $this->assertIsString($toastUtility);
        $this->assertStringContainsString('authorize account creation', $userManagement);
        $this->assertStringContainsString('retained for audit history', $userManagement);
        $this->assertStringContainsString("clearUser('receptionist')", $receptionistSettings);
        $this->assertStringContainsString("window.location.assign('/login')", $receptionistSettings);
        $this->assertStringContainsString('Use 12+ characters with uppercase, lowercase, a number, and a symbol.', $receptionistSettings);
        $this->assertStringContainsString('hhotel-toast-warning', $toastUtility);
        $this->assertStringContainsString('color: #ffffff !important', $toastUtility);
    }
}
