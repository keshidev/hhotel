<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Services\PromoCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromoCodeUsageSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_booking_reserves_promo_without_consuming_it(): void
    {
        $promo = $this->createPromo();
        $booking = $this->createBooking($promo);

        DB::transaction(function () use ($promo, $booking) {
            $result = app(PromoCodeService::class)->validateForReservation(
                $promo->code,
                $this->validationParams('guest@example.com')
            );

            $this->assertTrue($result['valid']);

            app(PromoCodeService::class)->recordUsage(
                $result['promo'],
                $booking,
                $result['discount_amount'],
                'Guest@Example.com '
            );
        });

        $usage = PromoCodeUsage::firstOrFail();

        $this->assertSame(PromoCodeUsage::STATUS_RESERVED, $usage->status);
        $this->assertSame('guest@example.com', $usage->guest_email);
        $this->assertNotNull($usage->reserved_at);
        $this->assertNull($usage->consumed_at);
        $this->assertSame(0, $promo->fresh()->total_used);
    }

    public function test_active_reservation_blocks_the_global_usage_limit(): void
    {
        $promo = $this->createPromo(['usage_limit' => 1]);
        $booking = $this->createBooking($promo);

        DB::transaction(function () use ($promo, $booking) {
            app(PromoCodeService::class)->recordUsage($promo, $booking, 50, 'first@example.com');
        });

        DB::transaction(function () use ($promo) {
            $result = app(PromoCodeService::class)->validateForReservation(
                $promo->code,
                $this->validationParams('second@example.com')
            );

            $this->assertFalse($result['valid']);
            $this->assertSame('This promo code has reached its maximum usage.', $result['message']);
        });
    }

    public function test_confirming_booking_consumes_reservation_exactly_once(): void
    {
        $promo = $this->createPromo();
        $booking = $this->createBooking($promo);

        DB::transaction(function () use ($promo, $booking) {
            app(PromoCodeService::class)->recordUsage($promo, $booking, 50, 'guest@example.com');
        });

        $booking->update(['booking_status' => 'confirmed']);

        $usage = PromoCodeUsage::firstOrFail();
        $this->assertSame(PromoCodeUsage::STATUS_CONSUMED, $usage->status);
        $this->assertNotNull($usage->consumed_at);
        $this->assertSame(1, $promo->fresh()->total_used);

        $booking->update(['booking_status' => 'checked_in']);

        $this->assertSame(PromoCodeUsage::STATUS_CONSUMED, $usage->fresh()->status);
        $this->assertSame(1, $promo->fresh()->total_used);
    }

    public function test_cancelling_pending_booking_releases_reservation(): void
    {
        $promo = $this->createPromo(['usage_limit' => 1]);
        $booking = $this->createBooking($promo);

        DB::transaction(function () use ($promo, $booking) {
            app(PromoCodeService::class)->recordUsage($promo, $booking, 50, 'first@example.com');
        });

        $booking->update(['booking_status' => 'cancelled']);

        $usage = PromoCodeUsage::firstOrFail();
        $this->assertSame(PromoCodeUsage::STATUS_RELEASED, $usage->status);
        $this->assertNotNull($usage->released_at);
        $this->assertSame('booking_cancelled', $usage->release_reason);
        $this->assertSame(0, $promo->fresh()->total_used);

        DB::transaction(function () use ($promo) {
            $result = app(PromoCodeService::class)->validateForReservation(
                $promo->code,
                $this->validationParams('second@example.com')
            );

            $this->assertTrue($result['valid']);
        });
    }

    public function test_reconciliation_repairs_consumed_counter_drift(): void
    {
        $promo = $this->createPromo(['total_used' => 9]);
        $booking = $this->createBooking($promo, 'confirmed');

        DB::transaction(function () use ($promo, $booking) {
            app(PromoCodeService::class)->recordUsage($promo, $booking, 50, 'guest@example.com');
        });

        $promo->update(['total_used' => 12]);

        $this->artisan('promos:reconcile-usage')
            ->expectsOutput('Reconciled: lifecycle_changes=0, counter_changes=1.')
            ->assertSuccessful();

        $this->assertSame(1, $promo->fresh()->total_used);
    }

    public function test_legacy_start_only_check_in_boundary_is_still_enforced(): void
    {
        $promo = $this->createPromo([
            'booking_start_date' => today()->addDays(5)->toDateString(),
            'booking_end_date' => null,
        ]);

        $result = app(PromoCodeService::class)->validate(
            $promo->code,
            $this->validationParams('guest@example.com')
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('check-ins on or after', $result['message']);
    }

    public function test_legacy_end_only_check_in_boundary_is_still_enforced(): void
    {
        $promo = $this->createPromo([
            'booking_start_date' => null,
            'booking_end_date' => today()->addDay()->toDateString(),
        ]);

        $result = app(PromoCodeService::class)->validate(
            $promo->code,
            $this->validationParams('guest@example.com')
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('check-ins on or before', $result['message']);
    }

    private function createPromo(array $overrides = []): PromoCode
    {
        return PromoCode::create(array_merge([
            'code' => 'SAFE50',
            'name' => 'Safety Promo',
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addMonth()->toDateString(),
            'min_nights' => 1,
            'usage_limit' => 5,
            'usage_per_user_limit' => 1,
            'total_used' => 0,
            'is_active' => true,
        ], $overrides));
    }

    private function createBooking(PromoCode $promo, string $status = 'pending'): Booking
    {
        return Booking::create([
            'check_in' => today()->addDays(2),
            'check_out' => today()->addDays(3),
            'number_of_guests' => 2,
            'booking_status' => $status,
            'reservation_status' => $status === 'confirmed' ? 'confirmed' : 'pending_verification',
            'total_amount' => 950,
            'booking_source' => 'online',
            'promo_code_id' => $promo->id,
            'discount_amount' => 50,
        ]);
    }

    private function validationParams(string $email): array
    {
        return [
            'guest_email' => $email,
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(3)->toDateString(),
            'subtotal' => 1000,
            'booking_source' => 'online',
        ];
    }
}
