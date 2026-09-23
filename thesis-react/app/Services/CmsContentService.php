<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CmsContentService
{
    private const JSON_KEYS = [
        'highlights_items',
        'stats_items',
        'testimonials_items',
        'nearby_items',
        'gallery_items',
        'policies_items',
        'addons_items',
    ];

    private const ADDON_IDS = [
        'rollaway_bed',
        'extra_pillows_and_blankets',
        'breakfast_package',
        'early_check_in',
        'late_check_out',
        'extra_toiletries_kit',
        'laundry_service',
    ];

    public function revision(Collection $settings): string
    {
        $payload = $settings
            ->sortBy('key')
            ->map(fn ($setting) => [
                'key' => (string) $setting->key,
                'value' => (string) ($setting->value ?? ''),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function normalize(string $key, string $value, string $errorKey): string
    {
        if (in_array($key, self::JSON_KEYS, true)) {
            return $this->normalizeJsonSetting($key, $value, $errorKey);
        }

        if (str_starts_with($key, 'brand_') && str_ends_with($key, '_color')) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                $this->fail($errorKey, 'Use a six-digit hexadecimal color such as #1a4bcc.');
            }

            return strtolower($value);
        }

        if (in_array($key, ['hero_primary_button_link', 'hero_secondary_button_link'], true)) {
            $this->assertNavigationUrl($value, $errorKey);
        } elseif ($key === 'location_map_url') {
            $this->assertGoogleMapsUrl($value, $errorKey);
        } elseif (str_ends_with($key, '_image')) {
            $this->assertImageUrl($value, $errorKey);
        } elseif ($key === 'location_contact_email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($errorKey, 'Enter a valid contact email address.');
        }

        $limit = str_starts_with($key, 'policy_') ? 10000 : 5000;
        if (mb_strlen($value) > $limit) {
            $this->fail($errorKey, "The content may not exceed {$limit} characters.");
        }

        return $value;
    }

    private function normalizeJsonSetting(string $key, string $value, string $errorKey): string
    {
        try {
            $items = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail($errorKey, 'The content has an invalid JSON structure.');
        }

        if (! is_array($items) || ! array_is_list($items)) {
            $this->fail($errorKey, 'The content must be a JSON list.');
        }

        $normalized = match ($key) {
            'highlights_items' => $this->normalizeHighlights($items, $errorKey),
            'stats_items' => $this->normalizeStats($items, $errorKey),
            'testimonials_items' => $this->normalizeTestimonials($items, $errorKey),
            'nearby_items' => $this->normalizeNearby($items, $errorKey),
            'gallery_items' => $this->normalizeGallery($items, $errorKey),
            'policies_items' => $this->normalizePolicies($items, $errorKey),
            'addons_items' => $this->normalizeAddons($items, $errorKey),
        };

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeHighlights(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, 12, $errorKey);

        return array_map(function ($item, $index) use ($errorKey) {
            $this->assertRow($item, $errorKey, $index);

            return [
                'title' => $this->boundedString($item['title'] ?? '', 100, $errorKey, $index, 'title'),
                'description' => $this->boundedString($item['description'] ?? '', 500, $errorKey, $index, 'description'),
            ];
        }, $items, array_keys($items));
    }

    private function normalizeStats(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, 12, $errorKey);

        return array_map(function ($item, $index) use ($errorKey) {
            $this->assertRow($item, $errorKey, $index);

            return [
                'value' => $this->boundedString($item['value'] ?? '', 30, $errorKey, $index, 'value'),
                'label' => $this->boundedString($item['label'] ?? '', 100, $errorKey, $index, 'label'),
            ];
        }, $items, array_keys($items));
    }

    private function normalizeTestimonials(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, 50, $errorKey);

        return array_map(function ($item, $index) use ($errorKey) {
            $this->assertRow($item, $errorKey, $index);
            $source = (string) ($item['source'] ?? 'manual');
            if (! in_array($source, ['manual', 'guest_feedback'], true)) {
                $this->fail($errorKey, 'Item '.($index + 1).' has an invalid source.');
            }

            $rating = filter_var($item['star_rating'] ?? 5, FILTER_VALIDATE_INT);
            if ($rating === false || $rating < 1 || $rating > 5) {
                $this->fail($errorKey, 'Item '.($index + 1).' must have a star rating from 1 to 5.');
            }

            $date = (string) ($item['date'] ?? '');
            if ($date !== '' && ! $this->isValidDate($date)) {
                $this->fail($errorKey, 'Item '.($index + 1).' has an invalid date.');
            }

            $feedbackId = isset($item['feedback_id']) && $item['feedback_id'] !== null
                ? filter_var($item['feedback_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : null;
            if ($source === 'guest_feedback' && ($feedbackId === false || $feedbackId === null)) {
                $this->fail($errorKey, 'Item '.($index + 1).' has an invalid feedback reference.');
            }

            $isActive = $item['is_active'] ?? true;
            if (! is_bool($isActive)) {
                $this->fail($errorKey, 'Item '.($index + 1).' has an invalid publication status.');
            }

            return [
                'id' => $this->boundedString($item['id'] ?? '', 100, $errorKey, $index, 'id'),
                'guest_name' => $this->boundedString($item['guest_name'] ?? '', 100, $errorKey, $index, 'guest name'),
                'review_text' => $this->boundedString($item['review_text'] ?? '', 1500, $errorKey, $index, 'review'),
                'star_rating' => (int) $rating,
                'date' => $date,
                'is_active' => $isActive,
                'source' => $source,
                'feedback_id' => $feedbackId === false ? null : $feedbackId,
            ];
        }, $items, array_keys($items));
    }

    private function normalizePolicies(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, 30, $errorKey);

        return array_map(function ($item, $index) use ($errorKey) {
            $this->assertRow($item, $errorKey, $index);

            return [
                'title' => $this->boundedString($item['title'] ?? '', 120, $errorKey, $index, 'title'),
                'body' => $this->boundedString($item['body'] ?? '', 3000, $errorKey, $index, 'body'),
            ];
        }, $items, array_keys($items));
    }

    private function normalizeNearby(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, 8, $errorKey);

        return array_map(function ($item, $index) use ($errorKey) {
            $this->assertRow($item, $errorKey, $index);
            $mapUrl = $this->boundedString($item['map_url'] ?? '', 2048, $errorKey, $index, 'map URL');
            $image = $this->boundedString($item['image'] ?? '', 2048, $errorKey, $index, 'image');
            $this->assertGoogleMapsLink($mapUrl, $errorKey);
            $this->assertImageUrl($image, $errorKey);

            return [
                'name' => $this->boundedString($item['name'] ?? '', 100, $errorKey, $index, 'name'),
                'category' => $this->boundedString($item['category'] ?? '', 80, $errorKey, $index, 'category'),
                'description' => $this->boundedString($item['description'] ?? '', 500, $errorKey, $index, 'description'),
                'map_url' => $mapUrl,
                'image' => $image,
            ];
        }, $items, array_keys($items));
    }

    private function normalizeGallery(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, 12, $errorKey);

        return array_map(function ($item, $index) use ($errorKey) {
            $this->assertRow($item, $errorKey, $index);
            $image = $this->boundedString($item['image'] ?? '', 2048, $errorKey, $index, 'image');
            $this->assertImageUrl($image, $errorKey);

            return [
                'image' => $image,
                'title' => $this->boundedString($item['title'] ?? '', 100, $errorKey, $index, 'title'),
                'alt' => $this->boundedString($item['alt'] ?? '', 180, $errorKey, $index, 'alternative text'),
            ];
        }, $items, array_keys($items));
    }

    private function normalizeAddons(array $items, string $errorKey): array
    {
        $this->assertItemLimit($items, count(self::ADDON_IDS), $errorKey);
        $seen = [];

        return array_map(function ($item, $index) use ($errorKey, &$seen) {
            $this->assertRow($item, $errorKey, $index);
            $id = (string) ($item['id'] ?? '');
            if (! in_array($id, self::ADDON_IDS, true) || isset($seen[$id])) {
                $this->fail($errorKey, 'Item '.($index + 1).' has an invalid or duplicate add-on ID.');
            }
            $seen[$id] = true;

            $image = (string) ($item['image'] ?? '');
            $this->assertImageUrl($image, $errorKey);

            return [
                'id' => $id,
                'name' => $this->boundedString($item['name'] ?? '', 100, $errorKey, $index, 'name'),
                'display_description' => $this->boundedString($item['display_description'] ?? '', 500, $errorKey, $index, 'description'),
                'image' => $image,
            ];
        }, $items, array_keys($items));
    }

    private function assertNavigationUrl(string $value, string $errorKey): void
    {
        if ($value === '' || preg_match('#^/(?!/)#', $value) === 1) {
            return;
        }

        if (! $this->isHttpsUrl($value)) {
            $this->fail($errorKey, 'Use a local path beginning with / or a valid HTTPS URL.');
        }
    }

    private function assertGoogleMapsUrl(string $value, string $errorKey): void
    {
        if ($value === '') {
            return;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        if (! $this->isHttpsUrl($value) || ! ($host === 'google.com' || str_ends_with($host, '.google.com'))) {
            $this->fail($errorKey, 'Use a valid HTTPS Google Maps embed URL.');
        }
    }

    private function assertGoogleMapsLink(string $value, string $errorKey): void
    {
        if ($value === '') {
            return;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $isGoogleHost = $host === 'google.com'
            || str_ends_with($host, '.google.com')
            || $host === 'goo.gl'
            || str_ends_with($host, '.goo.gl');

        if (! $this->isHttpsUrl($value) || ! $isGoogleHost) {
            $this->fail($errorKey, 'Use a valid HTTPS Google Maps link.');
        }
    }

    private function assertImageUrl(string $value, string $errorKey): void
    {
        if ($value === '' || preg_match('#^/(?:storage/cms|images)/[A-Za-z0-9%._/-]+$#', $value) === 1) {
            return;
        }

        if (! $this->isHttpsUrl($value) || mb_strlen($value) > 2048) {
            $this->fail($errorKey, 'Use a valid HTTPS image URL or an uploaded CMS image.');
        }
    }

    private function isHttpsUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }

    private function boundedString(mixed $value, int $limit, string $errorKey, int $index, string $field): string
    {
        if (! is_scalar($value) && $value !== null) {
            $this->fail($errorKey, 'Item '.($index + 1)." has an invalid {$field}.");
        }

        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value) > $limit) {
            $this->fail($errorKey, 'Item '.($index + 1)." {$field} may not exceed {$limit} characters.");
        }

        return $value;
    }

    private function assertItemLimit(array $items, int $limit, string $errorKey): void
    {
        if (count($items) > $limit) {
            $this->fail($errorKey, "This section may contain at most {$limit} items.");
        }
    }

    private function assertRow(mixed $item, string $errorKey, int $index): void
    {
        if (! is_array($item)) {
            $this->fail($errorKey, 'Item '.($index + 1).' must be an object.');
        }
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
