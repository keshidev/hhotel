<?php

namespace Tests\Feature;

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
}
