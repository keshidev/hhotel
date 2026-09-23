<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaffDatePickerTest extends TestCase
{
    public function test_targeted_staff_pages_use_the_shared_shadcn_date_picker(): void
    {
        $component = file_get_contents(base_path('../react/src/components/StaffDatePicker.jsx'));
        $styles = file_get_contents(base_path('../react/src/components/StaffDatePicker.css'));
        $walkIn = file_get_contents(base_path('../react/src/pages/receptionist/WalkIn.jsx'));
        $occupancy = file_get_contents(base_path('../react/src/pages/admin/reports/OccupancyReport.jsx'));
        $reservation = file_get_contents(base_path('../react/src/pages/admin/reports/ReservationReport.jsx'));
        $modified = file_get_contents(base_path('../react/src/pages/admin/reports/ModifiedReservationReport.jsx'));
        $revenue = file_get_contents(base_path('../react/src/pages/admin/reports/RevenueReport.jsx'));
        $audit = file_get_contents(base_path('../react/src/pages/admin/AuditTrail.jsx'));

        $this->assertStringContainsString("from '@/components/ui/calendar'", $component);
        $this->assertStringContainsString("from '@/components/ui/popover'", $component);
        $this->assertStringContainsString('{...props}', $component);
        $this->assertStringContainsString("button[data-selected-single='true']", $styles);
        $this->assertStringContainsString('color: #ffffff !important;', $styles);
        $this->assertStringContainsString('StaffDateTimePicker', $walkIn);
        $this->assertSame(3, substr_count($walkIn, '<StaffDateTimePicker'));

        foreach ([$occupancy, $reservation, $modified, $revenue, $audit] as $page) {
            $this->assertSame(2, substr_count($page, '<StaffDatePicker'));
            $this->assertStringNotContainsString('type="date"', $page);
        }

        $this->assertStringContainsString('min={form.checkIn}', $walkIn);
        $this->assertStringContainsString('max={toDatetimeLocal(new Date())}', $walkIn);
        $this->assertStringContainsString('clearable', $audit);
    }
}
