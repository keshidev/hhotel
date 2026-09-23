<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SystemSetting;
use App\Services\DownpaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DownpaymentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_downpayment_service_uses_the_admin_percentage(): void
    {
        SystemSetting::writeMany(['downpayment_percentage' => 35.5]);

        $downpayment = app(DownpaymentService::class);

        $this->assertSame(35.5, $downpayment->percentage());
        $this->assertSame(0.355, $downpayment->rate());
        $this->assertSame([
            'total' => 1500.0,
            'percentage' => 35.5,
            'rate' => 0.355,
            'amount' => 532.5,
            'remaining_balance' => 967.5,
        ], $downpayment->apply(1500));
    }

    public function test_public_cms_exposes_only_the_normalized_downpayment_rate(): void
    {
        SystemSetting::writeMany(['downpayment_percentage' => 40]);

        $this->getJson('/api/client/cms')
            ->assertOk()
            ->assertJsonPath('meta.booking_configuration.downpayment_rate', 0.4)
            ->assertJsonMissingPath('meta.booking_configuration.downpayment_percentage');
    }

    public function test_booking_downpayment_snapshot_preserves_the_original_percentage(): void
    {
        $booking = Booking::create([
            'check_in' => now()->addDay(),
            'check_out' => now()->addDays(2),
            'number_of_guests' => 1,
            'booking_status' => 'pending',
            'reservation_status' => 'pending_verification',
            'total_amount' => 1000,
            'downpayment_percentage' => 30,
        ]);

        SystemSetting::writeMany(['downpayment_percentage' => 60]);
        $booking->refresh();

        $downpayment = app(DownpaymentService::class);

        $this->assertSame('30.00', $booking->downpayment_percentage);
        $this->assertSame(300.0, $downpayment->calculate(
            (float) $booking->total_amount,
            (float) $booking->downpayment_percentage
        ));
        $this->assertSame(60.0, $downpayment->percentage());
    }

    public function test_configured_percentage_never_creates_a_zero_payment(): void
    {
        SystemSetting::writeMany(['downpayment_percentage' => 0]);

        $downpayment = app(DownpaymentService::class);

        $this->assertSame(1.0, $downpayment->percentage());
        $this->assertSame(10.0, $downpayment->calculate(1000));
    }
}
