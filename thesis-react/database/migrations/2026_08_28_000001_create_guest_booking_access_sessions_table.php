<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_booking_access_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 100)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'revoked_at'], 'guest_booking_access_booking_revoked_idx');
            $table->index('expires_at', 'guest_booking_access_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_booking_access_sessions');
    }
};
