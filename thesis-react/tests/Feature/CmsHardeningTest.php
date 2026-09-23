<?php

namespace Tests\Feature;

use App\Models\CmsSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CmsHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_cms_management_requires_an_active_admin(): void
    {
        $this->getJson('/api/admin/cms')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]));

        $this->getJson('/api/admin/cms')->assertForbidden();
    }

    public function test_admin_update_rejects_unknown_keys_and_invalid_content(): void
    {
        $this->actingAsAdmin();
        $revision = $this->revision();

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [['key' => 'invented_secret_setting', 'value' => 'unsafe']],
        ])->assertUnprocessable();

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [['key' => 'brand_primary_color', 'value' => 'red; background:url(javascript:alert(1))']],
        ])->assertUnprocessable();

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [['key' => 'hero_secondary_button_link', 'value' => 'javascript:alert(1)']],
        ])->assertUnprocessable();

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [['key' => 'highlights_items', 'value' => '{invalid']],
        ])->assertUnprocessable();
    }

    public function test_revision_conflict_prevents_silent_overwrite(): void
    {
        $this->actingAsAdmin();
        $revision = $this->revision();

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [['key' => 'hero_subtitle', 'value' => 'FIRST ADMIN']],
        ])->assertOk();

        $this->putJson('/api/admin/cms', [
            'revision' => $revision,
            'settings' => [['key' => 'hero_subtitle', 'value' => 'STALE ADMIN']],
        ])->assertConflict()
            ->assertJsonPath('error_code', 'CMS_REVISION_CONFLICT');

        $this->assertSame('FIRST ADMIN', CmsSetting::where('key', 'hero_subtitle')->value('value'));
    }

    public function test_every_editable_scalar_field_has_a_persisted_definition(): void
    {
        $expectedKeys = [
            'hero_title_line1',
            'hero_title_line2',
            'hero_subtitle',
            'hero_primary_button_text',
            'hero_primary_button_link',
            'hero_secondary_button_text',
            'hero_secondary_button_link',
            'hero_image',
            'about_eyebrow',
            'about_title',
            'about_description',
            'about_secondary_text',
            'about_image',
            'stats_heading',
            'stats_image',
            'testimonials_title',
            'gallery_title',
            'gallery_description',
            'nearby_title',
            'nearby_description',
            'policy_privacy_terms',
            'policy_booking_conditions',
            'location_title',
            'location_description',
            'location_address1',
            'location_address2',
            'location_address3',
            'location_contact_number',
            'location_contact_email',
            'location_map_url',
            'brand_primary_color',
            'brand_accent_color',
            'brand_button_color',
            'brand_button_hover_color',
        ];

        $persisted = CmsSetting::whereIn('key', $expectedKeys)->pluck('key')->all();

        $this->assertEmpty(array_diff($expectedKeys, $persisted));
    }

    public function test_admin_can_save_every_about_section_field(): void
    {
        $this->actingAsAdmin();
        $about = [
            'about_eyebrow' => 'WELCOME TO H+ HOTEL',
            'about_title' => 'COMFORT IN QUEZON CITY.',
            'about_description' => 'A convenient hotel for business and leisure stays.',
            'about_secondary_text' => 'Close to shopping, dining, and transport connections.',
            'about_image' => '/images/Executive%20Suite/executive_2.jpg',
        ];

        $response = $this->putJson('/api/admin/cms', [
            'revision' => $this->revision(),
            'settings' => collect($about)
                ->map(fn (string $value, string $key) => compact('key', 'value'))
                ->values()
                ->all(),
        ])->assertOk();

        $response->assertJsonPath('success', true);

        foreach ($about as $key => $value) {
            $this->assertSame($value, CmsSetting::where('key', $key)->value('value'));
        }

        $this->assertDatabaseHas('activity_logs', [
            'action_activity' => 'CMS Settings Updated',
            'record_affected' => 'CMS Sections - About Us',
        ]);

        $public = $this->getJson('/api/client/cms')->assertOk();
        foreach ($about as $key => $value) {
            $public->assertJsonPath("data.{$key}", $value);
        }
    }

    public function test_complete_seeded_cms_payload_can_round_trip_through_validation(): void
    {
        $this->actingAsAdmin();
        $response = $this->getJson('/api/admin/cms')->assertOk();
        $settings = collect($response->json('data'))
            ->map(fn (array $setting) => [
                'key' => $setting['key'],
                'value' => (string) ($setting['value'] ?? ''),
            ])
            ->values()
            ->all();

        $this->putJson('/api/admin/cms', [
            'revision' => $response->json('meta.revision'),
            'settings' => $settings,
        ])->assertOk();
    }

    public function test_empty_cms_lists_remain_empty_in_public_delivery(): void
    {
        CmsSetting::whereIn('key', [
            'highlights_items',
            'stats_items',
            'testimonials_items',
            'gallery_items',
            'nearby_items',
            'policies_items',
            'addons_items',
        ])->get()->each(function (CmsSetting $setting) {
            $setting->value = '[]';
            $setting->save();
        });

        $response = $this->getJson('/api/client/cms')->assertOk();

        foreach (['highlights_items', 'stats_items', 'testimonials_items', 'gallery_items', 'nearby_items', 'policies_items', 'addons_items'] as $key) {
            $this->assertSame([], json_decode((string) $response->json("data.{$key}"), true));
        }
    }

    public function test_homepage_destination_and_gallery_content_is_validated(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/admin/cms', [
            'revision' => $this->revision(),
            'settings' => [[
                'key' => 'nearby_items',
                'value' => json_encode([['name' => 'Unsafe', 'category' => 'Test', 'description' => 'Test', 'map_url' => 'javascript:alert(1)']]),
            ]],
        ])->assertUnprocessable();

        $this->putJson('/api/admin/cms', [
            'revision' => $this->revision(),
            'settings' => [[
                'key' => 'gallery_items',
                'value' => json_encode([['image' => 'javascript:alert(1)', 'title' => 'Unsafe', 'alt' => 'Unsafe']]),
            ]],
        ])->assertUnprocessable();
    }

    public function test_public_cache_is_invalidated_when_content_changes(): void
    {
        $this->getJson('/api/client/cms')->assertOk();

        $setting = CmsSetting::where('key', 'hero_subtitle')->firstOrFail();
        $setting->value = 'Fresh public content';
        $setting->save();

        $this->getJson('/api/client/cms')
            ->assertOk()
            ->assertJsonPath('data.hero_subtitle', 'Fresh public content');
    }

    public function test_image_uploads_are_restricted_and_unreferenced_files_are_pruned(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $this->postJson('/api/admin/cms/upload-image', [
            'image' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ])->assertUnprocessable();

        $upload = $this->post('/api/admin/cms/upload-image', [
            'image' => UploadedFile::fake()->createWithContent(
                'hero.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            ),
        ], ['Accept' => 'application/json'])->assertOk();

        $uploadedPath = $this->storagePathFromUrl((string) $upload->json('url'));
        Storage::disk('public')->assertExists($uploadedPath);

        $hero = CmsSetting::where('key', 'hero_image')->firstOrFail();
        $hero->value = (string) $upload->json('url');
        $hero->save();

        Storage::disk('public')->put('cms/orphan.jpg', 'orphan');

        $this->artisan('cms:prune-images', ['--hours' => 0])->assertSuccessful();

        Storage::disk('public')->assertExists($uploadedPath);
        Storage::disk('public')->assertMissing('cms/orphan.jpg');
    }

    public function test_frontend_uses_revisions_empty_lists_and_primary_link(): void
    {
        $adminCms = file_get_contents(base_path('../react/src/pages/admin/AdminCms.jsx'));
        $context = file_get_contents(base_path('../react/src/context/CmsContext.jsx'));
        $home = file_get_contents(base_path('../react/src/pages/Home.jsx'));

        $this->assertStringContainsString("api.put('/admin/cms', { settings: payload, revision })", $adminCms);
        $this->assertStringNotContainsString('if (parsedTestimonials.length > 0)', $adminCms);
        $this->assertStringNotContainsString('cmsTestimonials.length > 0 ? cmsTestimonials', $context);
        $this->assertStringNotContainsString('FALLBACK_TESTIMONIALS', $context);
        $this->assertStringContainsString("goToCmsLink('hero_primary_button_link'", $home);
        $this->assertStringContainsString('nearby_items', $adminCms);
        $this->assertStringContainsString('gallery_items', $adminCms);
        $this->assertStringContainsString('300000', $context);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function revision(): string
    {
        return (string) $this->getJson('/api/admin/cms')
            ->assertOk()
            ->json('meta.revision');
    }

    private function storagePathFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return ltrim((string) preg_replace('#^/storage/#', '', $path), '/');
    }
}
