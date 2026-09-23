<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ManualGcashConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualGcashConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('manual_gcash');
        Storage::fake('public');
    }

    public function test_admin_can_store_and_privately_preview_a_valid_merchant_qr(): void
    {
        $admin = $this->actingAsAdmin();
        $qr = $this->fakePng('official-hotel-qr.png', 800, 800);

        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'H Plus Hotel Incorporated',
            'account_number' => '0917 809 9482',
            'qr_image' => $qr,
            'ownership_confirmed' => '1',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.checkout_enabled', true)
            ->assertJsonPath('data.qr.available', true);

        $configuration = ManualGcashConfiguration::query()->firstOrFail();
        $this->assertSame('manual_gcash', $configuration->qr_disk);
        $this->assertStringNotContainsString('official-hotel-qr', $configuration->qr_path);
        $this->assertSame($admin->id, $configuration->configured_by);
        Storage::disk('manual_gcash')->assertExists($configuration->qr_path);
        Storage::disk('public')->assertMissing($configuration->qr_path);

        $response = $this->get('/api/admin/settings/manual-gcash/qr');
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotSame('', $response->streamedContent());

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action_activity' => 'Manual GCash Configuration Created',
            'action' => 'created',
        ]);

        $this->artisan('payments:manual-gcash-health')
            ->expectsOutput('Manual GCash merchant configuration is ready.')
            ->expectsOutput('Customer manual GCash proof workflow is installed.')
            ->assertSuccessful();
    }

    public function test_initial_configuration_requires_a_valid_raster_qr_image(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'H Plus Hotel Incorporated',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qr_image');

        $svg = UploadedFile::fake()->create('merchant-qr.svg', 10, 'image/svg+xml');
        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'H Plus Hotel Incorporated',
            'qr_image' => $svg,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qr_image');

        $small = $this->fakePng('too-small.png', 200, 200);
        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'H Plus Hotel Incorporated',
            'qr_image' => $small,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qr_image');

        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'H Plus Hotel Incorporated',
            'qr_image' => $this->fakePng('unconfirmed.png', 800, 800),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ownership_confirmed');

        $this->assertSame(0, ManualGcashConfiguration::query()->count());
        $this->artisan('payments:manual-gcash-health')->assertFailed();
    }

    public function test_replacing_a_qr_deletes_the_previous_private_file(): void
    {
        $this->actingAsAdmin();

        $this->uploadQr('first.png', 'First Account');
        $firstPath = ManualGcashConfiguration::query()->firstOrFail()->qr_path;

        $this->uploadQr('replacement.png', 'Replacement Account');
        $configuration = ManualGcashConfiguration::query()->firstOrFail();

        $this->assertNotSame($firstPath, $configuration->qr_path);
        $this->assertSame('Replacement Account', $configuration->account_name);
        Storage::disk('manual_gcash')->assertMissing($firstPath);
        Storage::disk('manual_gcash')->assertExists($configuration->qr_path);
        $this->assertSame(1, ManualGcashConfiguration::query()->count());
    }

    public function test_admin_can_remove_configuration_and_private_qr(): void
    {
        $admin = $this->actingAsAdmin();
        $this->uploadQr('remove-me.png', 'Official Account');
        $path = ManualGcashConfiguration::query()->firstOrFail()->qr_path;

        $this->deleteJson('/api/admin/settings/manual-gcash')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        $this->assertSame(0, ManualGcashConfiguration::query()->count());
        Storage::disk('manual_gcash')->assertMissing($path);
        $this->get('/api/admin/settings/manual-gcash/qr')->assertNotFound();
        $this->assertTrue(ActivityLog::query()
            ->where('user_id', $admin->id)
            ->where('action_activity', 'Manual GCash Configuration Removed')
            ->exists());
    }

    public function test_tampered_private_qr_is_rejected_until_an_admin_replaces_it(): void
    {
        $this->actingAsAdmin();
        $this->uploadQr('original.png', 'Official Account');
        $configuration = ManualGcashConfiguration::query()->firstOrFail();
        Storage::disk('manual_gcash')->put($configuration->qr_path, 'tampered-content');

        $this->getJson('/api/admin/settings/manual-gcash')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.qr.available', false)
            ->assertJsonPath('data.qr.integrity_valid', false)
            ->assertJsonPath('data.issues.0', 'manual_gcash_qr_integrity_failed');

        $this->get('/api/admin/settings/manual-gcash/qr')->assertNotFound();

        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => 'Official Account',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qr_image');

        $this->artisan('payments:manual-gcash-health')->assertFailed();
    }

    public function test_receptionist_cannot_access_or_modify_merchant_qr_configuration(): void
    {
        $receptionist = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
        ]);
        Sanctum::actingAs($receptionist);

        $this->getJson('/api/admin/settings/manual-gcash')->assertForbidden();
        $this->get('/api/admin/settings/manual-gcash/qr')->assertForbidden();
        $this->postJson('/api/admin/settings/manual-gcash', [])->assertForbidden();
        $this->deleteJson('/api/admin/settings/manual-gcash')->assertForbidden();
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

    private function uploadQr(string $filename, string $accountName): void
    {
        $this->post('/api/admin/settings/manual-gcash', [
            'merchant_name' => 'H+ Hotel',
            'account_name' => $accountName,
            'account_number' => '0917 809 9482',
            'qr_image' => $this->fakePng($filename, 800, 800),
            'ownership_confirmed' => '1',
        ], ['Accept' => 'application/json'])->assertOk();
    }

    private function fakePng(string $filename, int $width, int $height): UploadedFile
    {
        $row = "\x00".str_repeat("\xFF\xFF\xFF", $width);
        $pixels = str_repeat($row, $height);
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $header)
            .$this->pngChunk('IDAT', gzcompress($pixels, 9))
            .$this->pngChunk('IEND', '');

        return UploadedFile::fake()->createWithContent($filename, $png);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));
    }
}
