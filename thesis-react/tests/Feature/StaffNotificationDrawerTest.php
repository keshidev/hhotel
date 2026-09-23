<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaffNotificationDrawerTest extends TestCase
{
    public function test_both_staff_headers_use_the_shared_accessible_notification_drawer(): void
    {
        $adminHeader = file_get_contents(base_path('../react/src/components/AdminHeader.jsx'));
        $receptionistHeader = file_get_contents(base_path('../react/src/layouts/ReceptionistHeader.jsx'));
        $dropdown = file_get_contents(base_path('../react/src/components/NotificationDropdown.jsx'));
        $types = file_get_contents(base_path('../react/src/constants/notificationTypes.js'));

        $this->assertStringContainsString('<NotificationDropdown role="admin"', $adminHeader);
        $this->assertStringContainsString('<NotificationDropdown role="receptionist"', $receptionistHeader);
        $this->assertStringContainsString('setActiveNotif(n)', $dropdown);
        $this->assertStringContainsString('role="dialog"', $dropdown);
        $this->assertStringContainsString('aria-modal="true"', $dropdown);
        $this->assertStringContainsString('event.key === "Escape"', $dropdown);
        $this->assertStringContainsString('width: "min(390px, 100vw)"', $dropdown);
        $this->assertStringContainsString("ROOM_TRANSFER_REQUESTED: 'room_transfer_requested'", $types);
        $this->assertStringContainsString("ROOM_TRANSFER_APPROVED: 'room_transfer_approved'", $types);
        $this->assertStringContainsString("ROOM_TRANSFER_REJECTED: 'room_transfer_rejected'", $types);
        $this->assertStringContainsString("ROOM_TRANSFER_COMPLETED: 'room_transfer_completed'", $types);
        $this->assertStringContainsString('/admin/transfer-approvals', $dropdown);
        $this->assertStringContainsString('/receptionist/transfer-requests', $dropdown);
    }
}
