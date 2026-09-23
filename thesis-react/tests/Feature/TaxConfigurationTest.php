<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SystemSetting;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_service_uses_the_admin_tax_percentage(): void
    {
        SystemSetting::writeMany(['tax_percentage' => 8.25]);

        $tax = app(TaxService::class);

        $this->assertSame(0.0825, $tax->rate());
        $this->assertSame(76.21, $tax->calculate(1000));
        $this->assertSame([
            'subtotal' => 1000.0,
            'amount_before_tax' => 923.79,
            'tax_amount' => 76.21,
            'total' => 1000.0,
            'tax_rate' => 0.0825,
        ], $tax->apply(1000));
    }

    public function test_public_cms_exposes_only_the_normalized_tax_rate(): void
    {
        SystemSetting::writeMany(['tax_percentage' => 7.5]);

        $this->getJson('/api/client/cms')
            ->assertOk()
            ->assertJsonPath('meta.booking_configuration.tax_rate', 0.075)
            ->assertJsonMissingPath('meta.booking_configuration.tax_percentage');
    }

    public function test_booking_tax_snapshot_can_preserve_a_zero_tax_booking(): void
    {
        SystemSetting::writeMany(['tax_percentage' => 12]);

        $booking = Booking::create([
            'check_in' => now()->addDay(),
            'check_out' => now()->addDays(2),
            'number_of_guests' => 1,
            'booking_status' => 'pending',
            'reservation_status' => 'pending_verification',
            'total_amount' => 1000,
            'tax_amount' => 0,
            'tax_rate' => 0,
        ]);

        $booking->refresh();

        $this->assertSame('0.00000', $booking->tax_rate);
        $this->assertSame('0.00', $booking->tax_amount);
        $this->assertSame(0.0, app(TaxService::class)->calculate(1000, (float) $booking->tax_rate));
    }
}
