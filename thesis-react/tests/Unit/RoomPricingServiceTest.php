<?php

namespace Tests\Unit;

use App\Models\Room;
use App\Services\RoomPricingService;
use Tests\TestCase;

class RoomPricingServiceTest extends TestCase
{
    public function test_mapped_room_types_use_fixed_nightly_rates(): void
    {
        $service = app(RoomPricingService::class);

        $this->assertSame(1500.0, $service->resolveNightlyRateByType('deluxe', 9999.0));
        $this->assertSame(1000.0, $service->resolveNightlyRateByType('superior_queen', 9999.0));
        $this->assertSame(1000.0, $service->resolveNightlyRateByType('superior_twin', 9999.0));
        $this->assertSame(2000.0, $service->resolveNightlyRateByType('premier', 9999.0));
        $this->assertSame(3000.0, $service->resolveNightlyRateByType('executive_suite', 9999.0));
    }

    public function test_executive_alias_normalizes_to_executive_suite_rate(): void
    {
        $service = app(RoomPricingService::class);

        $this->assertSame(3000.0, $service->resolveNightlyRateByType('Executive', 1200.0));
        $this->assertSame(3000.0, $service->resolveNightlyRateByType('executive room', 1200.0));
        $this->assertSame(3000.0, $service->resolveNightlyRateByType('executive-suite', 1200.0));
    }

    public function test_unmapped_room_type_falls_back_to_room_rate(): void
    {
        $service = app(RoomPricingService::class);

        $this->assertSame(3500.0, $service->resolveNightlyRateByType('family', 3500.0));
        $this->assertSame(0.0, $service->resolveNightlyRateByType('unknown_type', null));
    }

    public function test_room_model_resolution_uses_fixed_rate_when_mapped(): void
    {
        $service = app(RoomPricingService::class);

        $room = new Room([
            'room_type' => 'Deluxe',
            'price_per_night' => 4800.00,
        ]);

        $this->assertSame(1500.0, $service->resolveNightlyRateForRoom($room));
    }
}

