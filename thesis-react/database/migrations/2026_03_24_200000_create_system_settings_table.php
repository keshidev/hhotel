<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        $seed = [
            'hotel_name' => config('app.name', 'H+ Hotel'),
            'contact_email' => config('mail.from.address', 'noreply@example.com'),
            'contact_phone' => '',
            'address' => '',
            'check_in_time' => '15:00',
            'check_out_time' => '12:00',
            'cancellation_policy' => '',
            'downpayment_percentage' => 50,
            'tax_percentage' => 12,
            'site_url' => config('app.frontend_url', ''),
            'timezone' => config('app.timezone', 'Asia/Manila'),
            'currency' => 'PHP',
            'email_notifications' => true,
            'booking_notifications' => true,
            'maintenance_mode' => false,
            'allow_registration' => true,
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
        ];

        $now = now();
        $rows = collect($seed)->map(function ($value, $key) use ($now) {
            return [
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values()->all();

        DB::table('system_settings')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
