<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\CmsSetting;
use App\Models\Feedback;
use App\Services\CmsContentService;
use App\Services\DownpaymentService;
use App\Services\GuestPrivacyService;
use App\Services\TaxService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CmsController extends Controller
{
    public function __construct(
        private TaxService $taxService,
        private DownpaymentService $downpaymentService,
        private GuestPrivacyService $guestPrivacy,
        private CmsContentService $contentService,
    ) {}

    // PUBLIC: Get all CMS settings (client-side consumption)
    // GET /client/cms
    public function public(): JsonResponse
    {
        $settings = Cache::remember(CmsSetting::PUBLIC_CACHE_KEY, now()->addMinutes(5), function () {
            $settings = CmsSetting::getAllAsMap();
            if (array_key_exists('testimonials_items', $settings)) {
                $canonicalTestimonials = $this->sanitizeFeedbackTestimonials(
                    (string) $settings['testimonials_items']
                );
                $publicTestimonials = collect($this->decodeJsonArray($canonicalTestimonials))
                    ->filter(fn (array $item) => ($item['is_active'] ?? true) !== false)
                    ->values()
                    ->all();
                $settings['testimonials_items'] = json_encode(
                    $publicTestimonials,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            return $settings;
        });

        return response()->json([
            'success' => true,
            'data' => $settings,
            'meta' => [
                'booking_configuration' => [
                    'tax_rate' => $this->taxService->rate(),
                    'downpayment_rate' => $this->downpaymentService->rate(),
                ],
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    // ADMIN: Get all settings grouped by section
    // GET /admin/cms
    public function index(): JsonResponse
    {
        $settings = CmsSetting::orderBy('group')->orderBy('key')->get();

        return response()->json([
            'success' => true,
            'data' => $settings,
            'meta' => [
                'available_feedback_testimonials' => $this->availableFeedbackTestimonialsCount(),
                'revision' => $this->contentService->revision($settings),
            ],
        ]);
    }

    // ADMIN: Update one or many settings
    // PUT /admin/cms
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'revision' => ['required', 'string', 'size:64'],
            'settings' => ['required', 'array', 'max:100'],
            'settings.*.key' => ['required', 'string', 'max:255', 'distinct', Rule::exists('cms_settings', 'key')],
            'settings.*.value' => ['nullable', 'string', 'max:100000'],
        ]);

        $incomingSettings = [];
        foreach ($request->settings as $index => $item) {
            $key = (string) $item['key'];
            $incomingSettings[$key] = $this->contentService->normalize(
                $key,
                (string) ($item['value'] ?? ''),
                "settings.{$index}.value"
            );
        }

        if (array_key_exists('testimonials_items', $incomingSettings)) {
            $incomingSettings['testimonials_items'] = $this->sanitizeFeedbackTestimonials(
                $incomingSettings['testimonials_items']
            );
        }

        [$oldValues, $newValues, $changedKeys, $revision] = DB::transaction(function () use ($incomingSettings, $request) {
            $settings = CmsSetting::query()
                ->orderBy('key')
                ->lockForUpdate()
                ->get();
            $currentRevision = $this->contentService->revision($settings);

            if (! hash_equals($currentRevision, (string) $request->input('revision'))) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Content changed in another session. Reload the CMS before saving again.',
                    'error_code' => 'CMS_REVISION_CONFLICT',
                    'current_revision' => $currentRevision,
                ], 409));
            }

            $settingsByKey = $settings->keyBy('key');

            $oldValues = [];
            $newValues = [];
            $changedKeys = [];

            foreach ($incomingSettings as $key => $value) {
                $setting = $settingsByKey->get($key);
                $oldValue = $setting?->value;

                $oldValues[$key] = $oldValue;
                $newValues[$key] = $value;

                if ((string) ($oldValue ?? '') !== $value) {
                    $setting->value = $value;
                    $setting->save();
                    $changedKeys[] = $key;
                }
            }

            return [$oldValues, $newValues, $changedKeys, $this->contentService->revision($settings)];
        });

        CmsSetting::forgetPublicCache();

        if (! empty($changedKeys)) {
            if (in_array('testimonials_items', $changedKeys, true)) {
                $this->syncFeedbackFeatureFlagsFromTestimonials(
                    (string) ($newValues['testimonials_items'] ?? '')
                );
            }

            $sections = $this->resolveSectionsFromKeys($changedKeys);

            $changedOldValues = [];
            $changedNewValues = [];
            foreach ($changedKeys as $key) {
                $changedOldValues[$key] = $oldValues[$key] ?? null;
                $changedNewValues[$key] = $newValues[$key] ?? null;
            }

            AuditHelper::log(
                actionActivity: 'CMS Settings Updated',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'CMS Sections - '.implode(', ', $sections),
                oldValues: $changedOldValues,
                newValues: array_merge($changedNewValues, [
                    'updated_sections' => $sections,
                    'updated_keys' => $changedKeys,
                    'updated_by' => auth()->user()?->name ?? 'Admin',
                    'updated_at' => now()->toDateTimeString(),
                ]),
                action: 'updated'
            );

            $this->logCmsSpecialActions($oldValues, $newValues, $changedKeys);
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'revision' => $revision,
        ]);
    }

    // ADMIN: Upload CMS image (hero, room image, add-on image, etc.)
    // POST /admin/cms/upload-image
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'key' => ['prohibited'],
        ]);

        $path = $request->file('image')->store('cms', 'public');
        if ($path === false) {
            return response()->json([
                'success' => false,
                'message' => 'The image could not be stored. Please retry.',
            ], 500);
        }
        $url = '/storage/'.$path;

        AuditHelper::log(
            actionActivity: 'CMS Image Uploaded',
            modulePage: 'CMS Module',
            modelType: 'CmsSetting',
            modelId: 0,
            recordAffected: 'CMS Image - temporary_upload',
            newValues: ['url' => $url],
            action: 'updated'
        );

        return response()->json([
            'success' => true,
            'url' => $url,
            'message' => 'Image uploaded successfully.',
        ]);
    }

    private function resolveSectionsFromKeys(array $keys): array
    {
        $sections = [];

        foreach ($keys as $key) {
            $sections[] = match (true) {
                str_starts_with($key, 'hero_') => 'Hero',
                str_starts_with($key, 'about_') => 'About Us',
                str_starts_with($key, 'highlights_'), str_starts_with($key, 'highlight_') => 'Highlights',
                str_starts_with($key, 'stats_'), str_starts_with($key, 'stat_') => 'Stats',
                str_starts_with($key, 'testimonials_') => 'Testimonials',
                str_starts_with($key, 'nearby_') => 'Nearby Places',
                str_starts_with($key, 'gallery_') => 'Gallery',
                str_starts_with($key, 'policy_'), str_starts_with($key, 'policies_') => 'Policies',
                str_starts_with($key, 'location_') => 'Location',
                str_starts_with($key, 'room_') => 'Rooms',
                str_starts_with($key, 'addons_') => 'Add-Ons',
                str_starts_with($key, 'brand_') => 'Brand',
                default => 'General',
            };
        }

        $sections = array_values(array_unique($sections));
        sort($sections);

        return $sections;
    }

    private function logCmsSpecialActions(array $oldValues, array $newValues, array $changedKeys): void
    {
        if (in_array('testimonials_items', $changedKeys, true)) {
            $this->logTestimonialChanges(
                (string) ($oldValues['testimonials_items'] ?? ''),
                (string) ($newValues['testimonials_items'] ?? '')
            );
        }

        foreach ($changedKeys as $key) {
            if (preg_match('/^room_(.+)_image$/', $key, $matches) !== 1) {
                continue;
            }

            $roomType = ucfirst(str_replace('_', ' ', (string) ($matches[1] ?? 'room')));
            AuditHelper::log(
                actionActivity: 'CMS Room Image Updated',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'Room Type - '.$roomType,
                oldValues: ['image' => $oldValues[$key] ?? null],
                newValues: ['image' => $newValues[$key] ?? null, 'key' => $key],
                action: 'updated'
            );
        }

        if (in_array('addons_items', $changedKeys, true)) {
            $this->logAddonDisplayChanges(
                (string) ($oldValues['addons_items'] ?? ''),
                (string) ($newValues['addons_items'] ?? '')
            );
        }

        $brandKeys = [
            'brand_primary_color' => 'Primary Color',
            'brand_accent_color' => 'Accent Color',
            'brand_button_color' => 'Button Color',
            'brand_button_hover_color' => 'Button Hover Color',
        ];

        foreach ($brandKeys as $key => $label) {
            if (! in_array($key, $changedKeys, true)) {
                continue;
            }

            AuditHelper::log(
                actionActivity: 'CMS Brand Color Updated',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'Brand - '.$label,
                oldValues: [$key => $oldValues[$key] ?? null],
                newValues: [$key => $newValues[$key] ?? null],
                action: 'updated'
            );
        }
    }

    private function logTestimonialChanges(string $oldRaw, string $newRaw): void
    {
        $oldItems = $this->decodeJsonArray($oldRaw);
        $newItems = $this->decodeJsonArray($newRaw);

        $oldSignatures = array_map(fn (array $item) => $this->testimonialSignature($item), $oldItems);
        $newSignatures = array_map(fn (array $item) => $this->testimonialSignature($item), $newItems);

        $addedSignatures = array_values(array_diff($newSignatures, $oldSignatures));
        $deletedSignatures = array_values(array_diff($oldSignatures, $newSignatures));

        foreach ($addedSignatures as $signature) {
            $entry = $this->findBySignature($newItems, $signature);
            if ($entry === null) {
                continue;
            }

            AuditHelper::log(
                actionActivity: 'CMS Testimonial Added',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'Testimonials',
                oldValues: null,
                newValues: $entry,
                action: 'created'
            );
        }

        foreach ($deletedSignatures as $signature) {
            $entry = $this->findBySignature($oldItems, $signature);
            if ($entry === null) {
                continue;
            }

            AuditHelper::log(
                actionActivity: 'CMS Testimonial Deleted',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'Testimonials',
                oldValues: $entry,
                newValues: null,
                action: 'deleted'
            );
        }

        $sortedOld = $oldSignatures;
        $sortedNew = $newSignatures;
        sort($sortedOld);
        sort($sortedNew);

        if (empty($addedSignatures) && empty($deletedSignatures) && $oldSignatures !== $newSignatures && $sortedOld === $sortedNew) {
            AuditHelper::log(
                actionActivity: 'CMS Testimonials Reordered',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'Testimonials',
                oldValues: [
                    'order' => array_map(fn (array $row) => (string) ($row['guest_name'] ?? 'Guest'), $oldItems),
                ],
                newValues: [
                    'order' => array_map(fn (array $row) => (string) ($row['guest_name'] ?? 'Guest'), $newItems),
                ],
                action: 'updated'
            );
        }
    }

    private function logAddonDisplayChanges(string $oldRaw, string $newRaw): void
    {
        $oldItems = [];
        foreach ($this->decodeJsonArray($oldRaw) as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $oldItems[$id] = $item;
            }
        }

        $newItems = [];
        foreach ($this->decodeJsonArray($newRaw) as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $newItems[$id] = $item;
            }
        }

        $allIds = array_values(array_unique(array_merge(array_keys($oldItems), array_keys($newItems))));

        foreach ($allIds as $id) {
            $oldItem = $oldItems[$id] ?? [];
            $newItem = $newItems[$id] ?? [];

            $oldDescription = (string) ($oldItem['display_description'] ?? '');
            $newDescription = (string) ($newItem['display_description'] ?? '');
            $oldImage = (string) ($oldItem['image'] ?? '');
            $newImage = (string) ($newItem['image'] ?? '');

            if ($oldDescription === $newDescription && $oldImage === $newImage) {
                continue;
            }

            AuditHelper::log(
                actionActivity: 'CMS Add-On Display Updated',
                modulePage: 'CMS Module',
                modelType: 'CmsSetting',
                modelId: 0,
                recordAffected: 'Add-On - '.((string) ($newItem['name'] ?? $oldItem['name'] ?? $id)),
                oldValues: [
                    'display_description' => $oldDescription,
                    'image' => $oldImage,
                ],
                newValues: [
                    'display_description' => $newDescription,
                    'image' => $newImage,
                    'addon_id' => $id,
                ],
                action: 'updated'
            );
        }
    }

    private function decodeJsonArray(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, fn ($row) => is_array($row)))
            : [];
    }

    private function testimonialSignature(array $item): string
    {
        $payload = [
            'guest_name' => strtolower(trim((string) ($item['guest_name'] ?? ''))),
            'review_text' => trim((string) ($item['review_text'] ?? '')),
            'star_rating' => (int) ($item['star_rating'] ?? 0),
            'date' => (string) ($item['date'] ?? ''),
            'is_active' => (bool) ($item['is_active'] ?? false),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function findBySignature(array $items, string $signature): ?array
    {
        foreach ($items as $item) {
            if ($this->testimonialSignature($item) === $signature) {
                return [
                    'guest_name' => (string) ($item['guest_name'] ?? ''),
                    'review_text' => (string) ($item['review_text'] ?? ''),
                    'star_rating' => (int) ($item['star_rating'] ?? 0),
                    'date' => (string) ($item['date'] ?? ''),
                    'is_active' => (bool) ($item['is_active'] ?? false),
                ];
            }
        }

        return null;
    }

    private function availableFeedbackTestimonialsCount(): int
    {
        return Feedback::query()
            ->where('is_submitted', true)
            ->where('rating_overall', '>=', 4)
            ->whereNotNull('review')
            ->where('review', '!=', '')
            ->where(function ($query) {
                $query->whereNull('is_featured')
                    ->orWhere('is_featured', false);
            })
            ->count();
    }

    private function syncFeedbackFeatureFlagsFromTestimonials(string $raw): void
    {
        $items = $this->decodeJsonArray($raw);

        $candidateIds = collect($items)
            ->filter(function (array $item) {
                return ((string) ($item['source'] ?? '') === 'guest_feedback' || (int) ($item['feedback_id'] ?? 0) > 0)
                    && (int) ($item['feedback_id'] ?? 0) > 0
                    && (($item['is_active'] ?? true) !== false);
            })
            ->map(fn (array $item) => (int) $item['feedback_id'])
            ->unique()
            ->values()
            ->all();

        $featuredIds = Feedback::query()
            ->whereIn('id', $candidateIds)
            ->where('is_submitted', true)
            ->where('rating_overall', '>=', 4)
            ->whereNotNull('review')
            ->where('review', '!=', '')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($featuredIds)) {
            Feedback::query()->where('is_featured', true)->update(['is_featured' => false]);

            return;
        }

        Feedback::query()
            ->where('is_featured', true)
            ->whereNotIn('id', $featuredIds)
            ->update(['is_featured' => false]);

        Feedback::query()
            ->whereIn('id', $featuredIds)
            ->where('is_submitted', true)
            ->where('rating_overall', '>=', 4)
            ->whereNotNull('review')
            ->where('review', '!=', '')
            ->update(['is_featured' => true]);
    }

    private function sanitizeFeedbackTestimonials(string $raw): string
    {
        $items = $this->decodeJsonArray($raw);
        $feedbackIds = collect($items)
            ->map(fn (array $item) => (int) ($item['feedback_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        $feedbacks = Feedback::query()
            ->with('booking.primaryGuest')
            ->whereIn('id', $feedbackIds)
            ->where('is_submitted', true)
            ->get()
            ->keyBy('id');

        $sanitized = [];
        foreach ($items as $item) {
            $feedbackId = (int) ($item['feedback_id'] ?? 0);
            $isFeedbackSourced = (string) ($item['source'] ?? '') === 'guest_feedback' || $feedbackId > 0;

            if (! $isFeedbackSourced) {
                $sanitized[] = $item;

                continue;
            }

            $feedback = $feedbacks->get($feedbackId);
            if (! $feedback) {
                continue;
            }

            $eligibleForPublication = (int) $feedback->rating_overall >= 4
                && trim((string) $feedback->review) !== '';

            $sanitized[] = [
                'id' => 'feedback-'.$feedback->id,
                'guest_name' => $this->guestPrivacy->maskedName($feedback->booking?->primaryGuest?->name),
                'review_text' => (string) $feedback->review,
                'star_rating' => max(1, min(5, (int) $feedback->rating_overall)),
                'date' => $feedback->submitted_at?->toDateString() ?? now()->toDateString(),
                'is_active' => $eligibleForPublication && (($item['is_active'] ?? false) === true),
                'source' => 'guest_feedback',
                'feedback_id' => (int) $feedback->id,
            ];
        }

        return json_encode(array_values($sanitized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
