<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlineBookingMaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_mode_blocks_room_searches_and_new_online_bookings(): void
    {
        SystemSetting::writeMany(['maintenance_mode' => true]);

        $availability = $this->getJson('/api/client/rooms/available');

        $availability
            ->assertStatus(503)
            ->assertExactJson([
                'success' => false,
                'error_code' => 'BOOKING_MAINTENANCE',
                'message' => 'Online booking is temporarily unavailable while we perform maintenance. Please try again later or contact the hotel.',
            ])
            ->assertHeader('Retry-After', '300');

        $this->assertStringContainsString('no-store', $availability->headers->get('Cache-Control'));

        $this->getJson('/api/client/rooms/availability-calendar')
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'BOOKING_MAINTENANCE');

        $this->postJson('/api/client/bookings')
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'BOOKING_MAINTENANCE');
    }

    public function test_maintenance_mode_does_not_block_existing_manual_payment_or_health_flows(): void
    {
        config()->set([
            'payment.provider' => 'manual_gcash',
        ]);
        SystemSetting::writeMany(['maintenance_mode' => true]);

        $paymentResponse = $this->postJson('/api/client/manual-gcash/999999/prepare');
        $paymentResponse->assertJsonMissing(['error_code' => 'BOOKING_MAINTENANCE']);

        $healthResponse = $this->getJson('/api/system/scheduler-health');
        $healthResponse
            ->assertStatus(503)
            ->assertExactJson(['status' => 'missing']);
    }

    public function test_online_booking_validation_remains_available_when_maintenance_mode_is_disabled(): void
    {
        SystemSetting::writeMany(['maintenance_mode' => false]);

        $response = $this->postJson('/api/client/bookings');

        $response
            ->assertStatus(422)
            ->assertJsonMissing(['error_code' => 'BOOKING_MAINTENANCE']);
    }

    public function test_admin_can_enable_maintenance_mode_with_blank_optional_settings(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings', [
            'maintenance_mode' => true,
            'contact_email' => null,
            'contact_phone' => null,
            'address' => null,
            'cancellation_policy' => null,
        ])->assertOk()
            ->assertJsonPath('settings.maintenance_mode', true);

        $this->assertTrue((bool) SystemSetting::read('maintenance_mode'));
    }
}
