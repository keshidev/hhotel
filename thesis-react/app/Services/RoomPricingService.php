<?php

namespace App\Services;

use App\Models\Room;

class RoomPricingService
{
    /**
     * Children aged 8+ are policy-flagged as adults for occupancy only.
     * No per-person surcharge is applied because pricing is room-based.
     * If per-person pricing is introduced later, update this service.
     */
    /**
     * Fixed nightly rates required by current PMS pricing policy.
     */
    private const FIXED_NIGHTLY_RATES = [
        'deluxe' => 1500.00,
        'superior_queen' => 1000.00,
        'superior_twin' => 1000.00,
        'premier' => 2000.00,
        'executive_suite' => 3000.00,
    ];

    /**
     * Normalization aliases for room type inputs.
     */
    private const ROOM_TYPE_ALIASES = [
        'executive' => 'executive_suite',
        'executive_room' => 'executive_suite',
        'executive_suite' => 'executive_suite',
        'deluxe_room' => 'deluxe',
        'superiorqueen' => 'superior_queen',
        'superiortwin' => 'superior_twin',
    ];

    public function resolveNightlyRateForRoom(Room $room): float
    {
        return $this->resolveNightlyRateByType($room->room_type, (float) $room->price_per_night);
    }

    public function resolveDayUseRateForRoom(Room $room): float
    {
        $nightlyRate = $this->resolveNightlyRateForRoom($room);
        return $this->resolveDayUseRate((float) ($room->price_day_tour ?? 0), $nightlyRate);
    }

    public function resolveNightlyRateByType(?string $roomType, ?float $fallbackRate = null): float
    {
        $normalized = $this->normalizeRoomType($roomType);
        if ($normalized && array_key_exists($normalized, self::FIXED_NIGHTLY_RATES)) {
            return (float) self::FIXED_NIGHTLY_RATES[$normalized];
        }

        return round((float) ($fallbackRate ?? 0), 2);
    }

    public function hasFixedRate(?string $roomType): bool
    {
        $normalized = $this->normalizeRoomType($roomType);
        return $normalized !== null && array_key_exists($normalized, self::FIXED_NIGHTLY_RATES);
    }

    public function resolveDayUseRate(?float $configuredDayUseRate, float $nightlyRate): float
    {
        $configured = round((float) ($configuredDayUseRate ?? 0), 2);
        if ($configured > 0) {
            return $configured;
        }

        return round(max(0, $nightlyRate) * 0.65, 2);
    }

    public function normalizeRoomType(?string $roomType): ?string
    {
        if ($roomType === null) {
            return null;
        }

        $normalized = strtolower(trim($roomType));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['-', ' '], '_', $normalized);
        if (isset(self::ROOM_TYPE_ALIASES[$normalized])) {
            return self::ROOM_TYPE_ALIASES[$normalized];
        }

        return $normalized;
    }
}
