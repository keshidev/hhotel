<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_gcash_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('configuration_key', 32)->unique();
            $table->string('merchant_name', 120);
            $table->string('account_name', 120);
            $table->string('account_number', 25)->nullable();
            $table->string('qr_disk', 32)->default('manual_gcash');
            $table->string('qr_path');
            $table->string('qr_original_name');
            $table->string('qr_mime_type', 50);
            $table->unsignedBigInteger('qr_size');
            $table->unsignedInteger('qr_width');
            $table->unsignedInteger('qr_height');
            $table->char('qr_sha256', 64);
            $table->timestamp('configured_at');
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_gcash_configurations');
    }
};
