<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPromoOffersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_current_online_eligible_offers(): void
    {
        $online = $this->createPromo([
            'code' => 'ONLINE20',
            'name' => 'Online Offer',
            'online_only' => true,
        ]);
        $allChannels = $this->createPromo([
            'code' => 'WELCOME10',
            'name' => 'Welcome Offer',
        ]);

        $this->createPromo([
            'code' => 'WALKIN15',
            'name' => 'Walk-in Only',
            'walk_in_only' => true,
        ]);
        $this->createPromo([
            'code' => 'EXPIRED10',
            'name' => 'Expired Offer',
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => today()->subDay()->toDateString(),
        ]);
        $this->createPromo([
            'code' => 'INACTIVE10',
            'name' => 'Inactive Offer',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/client/promo-codes/offers');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $online->id, 'code' => 'ONLINE20'])
            ->assertJsonFragment(['id' => $allChannels->id, 'code' => 'WELCOME10'])
            ->assertJsonMissing(['code' => 'WALKIN15'])
            ->assertJsonMissing(['code' => 'EXPIRED10'])
            ->assertJsonMissing(['code' => 'INACTIVE10'])
            ->assertJsonMissingPath('data.0.active_usage_count')
            ->assertJsonMissingPath('data.0.usage_limit');
    }

    private function createPromo(array $overrides = []): PromoCode
    {
        return PromoCode::create(array_merge([
            'code' => 'OFFER10',
            'name' => 'Current Offer',
            'description' => 'Save on an eligible online stay.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addMonth()->toDateString(),
            'min_nights' => 1,
            'usage_per_user_limit' => 1,
            'total_used' => 0,
            'online_only' => false,
            'walk_in_only' => false,
            'is_active' => true,
        ], $overrides));
    }
}
