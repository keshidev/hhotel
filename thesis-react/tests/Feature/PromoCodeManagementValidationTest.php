<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromoCodeManagementValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_check_in_start_requires_an_end_date(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/admin/promo-codes', array_merge($this->validPayload(), [
            'booking_start_date' => today()->addDays(5)->toDateString(),
            'booking_end_date' => null,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_end_date');
    }

    public function test_eligible_check_in_end_requires_a_start_date(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/admin/promo-codes', array_merge($this->validPayload(), [
            'booking_start_date' => null,
            'booking_end_date' => today()->addDays(10)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_start_date');
    }

    public function test_a_complete_eligible_check_in_range_is_accepted(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/admin/promo-codes', array_merge($this->validPayload(), [
            'booking_start_date' => today()->addDays(5)->toDateString(),
            'booking_end_date' => today()->addDays(10)->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'CHECKIN10');
    }

    public function test_global_usage_limit_cannot_be_lower_than_active_usage_count(): void
    {
        $this->authenticateAdmin();
        $promo = PromoCode::create($this->validPayload());
        $this->createUsage($promo, PromoCodeUsage::STATUS_CONSUMED, 1);
        $this->createUsage($promo, PromoCodeUsage::STATUS_RESERVED, 2);
        $this->createUsage($promo, PromoCodeUsage::STATUS_RELEASED, 3);

        $this->putJson("/api/admin/promo-codes/{$promo->id}", array_merge($this->validPayload(), [
            'usage_limit' => 1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usage_limit');

        $this->assertNull($promo->fresh()->usage_limit);
    }

    public function test_global_usage_limit_can_equal_active_usage_count_or_be_unlimited(): void
    {
        $this->authenticateAdmin();
        $promo = PromoCode::create($this->validPayload());
        $this->createUsage($promo, PromoCodeUsage::STATUS_CONSUMED, 1);
        $this->createUsage($promo, PromoCodeUsage::STATUS_RESERVED, 2);

        $this->putJson("/api/admin/promo-codes/{$promo->id}", array_merge($this->validPayload(), [
            'usage_limit' => 2,
        ]))->assertOk();

        $this->assertSame(2, $promo->fresh()->usage_limit);

        $this->putJson("/api/admin/promo-codes/{$promo->id}", array_merge($this->validPayload(), [
            'usage_limit' => null,
        ]))->assertOk();

        $this->assertNull($promo->fresh()->usage_limit);
    }

    public function test_promo_with_released_usage_history_must_be_deactivated_instead_of_deleted(): void
    {
        $this->authenticateAdmin();
        $promo = PromoCode::create($this->validPayload());
        $this->createUsage($promo, PromoCodeUsage::STATUS_RELEASED, 1);

        $this->deleteJson("/api/admin/promo-codes/{$promo->id}")
            ->assertConflict()
            ->assertJsonPath('message', "Cannot delete promo code {$promo->code} because it has booking usage history. Deactivate it instead.");

        $this->assertDatabaseHas('promo_codes', ['id' => $promo->id]);
    }

    private function authenticateAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));
    }

    private function validPayload(): array
    {
        return [
            'code' => 'CHECKIN10',
            'name' => 'Eligible Check-in Offer',
            'description' => 'Valid for a specific check-in period.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'max_discount_amount' => 1000,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addMonth()->toDateString(),
            'min_nights' => 1,
            'usage_per_user_limit' => 1,
            'online_only' => true,
            'walk_in_only' => false,
            'is_active' => true,
        ];
    }

    private function createUsage(PromoCode $promo, string $status, int $sequence): void
    {
        $booking = Booking::create([
            'reference_number' => 'PROMO-TEST-' . $sequence,
            'check_in' => today()->addMonth()->toDateString(),
            'check_out' => today()->addMonth()->addDay()->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => $status === PromoCodeUsage::STATUS_CONSUMED ? 'confirmed' : 'cancelled',
            'total_amount' => 1000,
            'booking_source' => 'online',
            'promo_code_id' => $promo->id,
            'discount_amount' => 100,
        ]);

        PromoCodeUsage::create([
            'promo_code_id' => $promo->id,
            'booking_id' => $booking->id,
            'guest_email' => "promo-test-{$sequence}@example.com",
            'discount_amount' => 100,
            'status' => $status,
            'reserved_at' => now(),
            'consumed_at' => $status === PromoCodeUsage::STATUS_CONSUMED ? now() : null,
            'released_at' => $status === PromoCodeUsage::STATUS_RELEASED ? now() : null,
            'used_at' => now(),
        ]);
    }
}
