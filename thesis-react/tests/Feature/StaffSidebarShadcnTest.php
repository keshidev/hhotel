<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaffSidebarShadcnTest extends TestCase
{
    public function test_staff_layouts_use_the_shared_shadcn_sidebar_without_losing_routes(): void
    {
        $adminLayout = file_get_contents(base_path('../react/src/components/AdminLayout.jsx'));
        $receptionistLayout = file_get_contents(base_path('../react/src/layouts/ReceptionistLayout.jsx'));
        $sharedSidebar = file_get_contents(base_path('../react/src/components/StaffSidebar.jsx'));
        $adminSidebar = file_get_contents(base_path('../react/src/components/Sidebar.jsx'));
        $receptionistSidebar = file_get_contents(base_path('../react/src/components/ReceptionistSidebar.jsx'));

        $this->assertStringContainsString('SidebarProvider', $adminLayout);
        $this->assertStringContainsString('SidebarProvider', $receptionistLayout);
        $this->assertStringContainsString('SidebarMenuButton', $sharedSidebar);
        $this->assertStringContainsString('CollapsibleTrigger', $sharedSidebar);
        $this->assertStringContainsString('SidebarMenuBadge', $sharedSidebar);

        foreach ([
            '/admin/dashboard',
            '/admin/users',
            '/admin/rooms',
            '/admin/reports/revenue',
            '/admin/audit-trail',
            '/admin/settings',
        ] as $route) {
            $this->assertStringContainsString($route, $adminSidebar);
        }

        foreach ([
            '/receptionist/dashboard',
            '/receptionist/reservation',
            '/receptionist/payment',
            '/receptionist/check-in',
            '/receptionist/check-out',
            '/receptionist/settings',
        ] as $route) {
            $this->assertStringContainsString($route, $receptionistSidebar);
        }

        $this->assertStringContainsString('receptionist:checkin-count-delta', $receptionistSidebar);
        $this->assertStringContainsString('ConfirmDialog', $sharedSidebar);
    }
}
