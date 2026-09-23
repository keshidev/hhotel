<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\AddOnCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddonAndExtraChargeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_addon_pricing_ignores_client_prices_and_line_totals(): void
    {
        $normalized = app(AddOnCatalogService::class)->normalizeSelections([
            '0' => [[
                'id' => 'rollaway_bed',
                'quantity' => 2,
                'price' => 0.01,
                'line_total' => 0.01,
                'name' => 'Cheap custom bed',
            ]],
        ]);

        $this->assertSame('Rollaway Bed', $normalized['0'][0]['name']);
        $this->assertSame(800.0, $normalized['0'][0]['price']);
        $this->assertSame(2, $normalized['0'][0]['quantity']);
        $this->assertSame(1600.0, $normalized['0'][0]['line_total']);
        $this->assertSame(1600.0, app(AddOnCatalogService::class)->totalForNormalizedSelections($normalized));
    }

    public function test_duplicate_addon_rows_are_consolidated_and_bounded(): void
    {
        $normalized = app(AddOnCatalogService::class)->normalizeSelections([
            '0' => [
                ['id' => 'rollaway_bed', 'quantity' => 1],
                ['id' => 'rollaway_bed', 'quantity' => 1],
            ],
        ]);

        $this->assertCount(1, $normalized['0']);
        $this->assertSame(2, $normalized['0'][0]['quantity']);

        $this->expectException(ValidationException::class);
        app(AddOnCatalogService::class)->normalizeSelections([
            '0' => [['id' => 'rollaway_bed', 'quantity' => 3]],
        ]);
    }

    public function test_unknown_addons_are_rejected_instead_of_using_client_fallback_prices(): void
    {
        $this->expectException(ValidationException::class);

        app(AddOnCatalogService::class)->normalizeSelections([
            '0' => [[
                'id' => 'custom_vip_service',
                'quantity' => 1,
                'price' => 1,
                'line_total' => 1,
            ]],
        ]);
    }

    public function test_extra_charge_retry_is_idempotent_and_total_changes_once(): void
    {
        $staff = $this->actingReceptionist();
        $booking = $this->booking('checked_in', 'BK-CHARGE-IDEMPOTENT');
        $requestKey = '31f18eb1-fb40-48d5-bd76-a2352b55276d';
        $payload = [
            'idempotency_key' => $requestKey,
            'charges' => [[
                'description' => 'Broken television remote',
                'category' => 'Broken Item',
                'amount' => 250,
            ]],
        ];

        $this->postJson("/api/receptionist/bookings/{$booking->id}/extra-charges", $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('total_amount', 1250);

        $this->postJson("/api/receptionist/bookings/{$booking->id}/extra-charges", $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('total_amount', 1250);

        $this->assertDatabaseCount('booking_charges', 1);
        $this->assertDatabaseHas('booking_charges', [
            'booking_id' => $booking->id,
            'created_by' => $staff->id,
            'operation_token' => $requestKey,
            'operation_line' => 0,
            'amount' => 250,
        ]);
        $this->assertSame(1250.0, (float) $booking->fresh()->total_amount);
    }

    public function test_reusing_charge_key_with_different_details_is_rejected(): void
    {
        $this->actingReceptionist();
        $booking = $this->booking('checked_in', 'BK-CHARGE-CONFLICT');
        $requestKey = '97188c02-92d5-41db-946b-f05c20b45ca3';

        $this->postJson("/api/receptionist/bookings/{$booking->id}/extra-charges", [
            'idempotency_key' => $requestKey,
            'charges' => [[
                'description' => 'Lost room key card',
                'category' => 'Lost Key / Card',
                'amount' => 200,
            ]],
        ])->assertOk();

        $this->postJson("/api/receptionist/bookings/{$booking->id}/extra-charges", [
            'idempotency_key' => $requestKey,
            'charges' => [[
                'description' => 'Lost room key card',
                'category' => 'Lost Key / Card',
                'amount' => 500,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('booking_charges', 1);
        $this->assertSame(1200.0, (float) $booking->fresh()->total_amount);
    }

    public function test_extra_charges_cannot_be_posted_before_check_in(): void
    {
        $this->actingReceptionist();
        $booking = $this->booking('confirmed', 'BK-CHARGE-EARLY');

        $this->postJson("/api/receptionist/bookings/{$booking->id}/extra-charges", [
            'idempotency_key' => '764fc12d-9c5e-45f9-9acf-a0fba45be11a',
            'charges' => [[
                'description' => 'Charge before arrival',
                'category' => 'Other',
                'amount' => 100,
            ]],
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Extra charges can only be added after the guest has checked in.');

        $this->assertDatabaseCount('booking_charges', 0);
        $this->assertSame(1000.0, (float) $booking->fresh()->total_amount);
    }

    private function actingReceptionist(): User
    {
        $staff = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]);
        Sanctum::actingAs($staff);

        return $staff;
    }

    private function booking(string $status, string $reference): Booking
    {
        return Booking::create([
            'reference_number' => $reference,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDay()->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => $status,
            'reservation_status' => 'confirmed',
            'total_amount' => 1000,
            'tax_rate' => 0.12,
        ]);
    }
}
