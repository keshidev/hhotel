<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AddOnCatalogService
{
    public function catalog(): array
    {
        return (array) config('addons.catalog', []);
    }

    public function normalizeSelections(array $payload): array
    {
        $catalog = $this->catalog();
        $normalized = [];
        $maxDistinctItems = max(1, (int) config('addons.max_distinct_items_per_room', count($catalog)));

        foreach ($payload as $roomId => $addons) {
            if (!is_array($addons)) {
                $this->fail('Each room add-on selection must be a valid list.');
            }

            if (count($addons) > $maxDistinctItems) {
                $this->fail("A room may contain at most {$maxDistinctItems} different add-ons.");
            }

            $roomKey = (string) ((int) $roomId);
            $roomSelections = [];

            foreach ($addons as $addon) {
                if (!is_array($addon)) {
                    $this->fail('Each selected add-on must be valid.');
                }

                $addonId = trim((string) ($addon['id'] ?? $addon['addon_id'] ?? ''));
                $catalogEntry = $catalog[$addonId] ?? null;

                if (!$catalogEntry) {
                    $this->fail('One or more selected add-ons are no longer available. Please review your selections.');
                }

                $quantity = filter_var($addon['quantity'] ?? 1, FILTER_VALIDATE_INT);
                $maxQuantity = max(1, (int) ($catalogEntry['max_quantity'] ?? 1));
                if ($quantity === false || $quantity < 1) {
                    $this->fail('Add-on quantities must be whole numbers of at least 1.');
                }

                $existingQuantity = (int) ($roomSelections[$addonId]['quantity'] ?? 0);
                $quantity += $existingQuantity;
                if ($quantity > $maxQuantity) {
                    $name = (string) ($catalogEntry['name'] ?? 'This add-on');
                    $this->fail("{$name} allows a maximum quantity of {$maxQuantity} per room.");
                }

                $unitPrice = round((float) ($catalogEntry['price'] ?? 0), 2);
                if ($unitPrice <= 0) {
                    $this->fail('One or more selected add-ons have invalid catalog pricing.');
                }

                $roomSelections[$addonId] = [
                    'id' => (string) ($catalogEntry['id'] ?? $addonId),
                    'name' => (string) ($catalogEntry['name'] ?? 'Add-on'),
                    'price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => round($unitPrice * $quantity, 2),
                ];
            }

            if ($roomSelections !== []) {
                $normalized[$roomKey] = array_values($roomSelections);
            }
        }

        return $normalized;
    }

    public function totalForSelections(array $payload): float
    {
        return $this->totalForNormalizedSelections($this->normalizeSelections($payload));
    }

    public function totalForNormalizedSelections(array $normalized): float
    {
        $total = 0.0;
        foreach ($normalized as $addons) {
            foreach ($addons as $addon) {
                $total += (float) $addon['line_total'];
            }
        }

        return round($total, 2);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['room_addons' => [$message]]);
    }
}
