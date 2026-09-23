<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();

            // ── Identity ─────────────────────────────────────────────────────
            $table->string('code')->unique();           // e.g. SUMMER20
            $table->string('name');                     // e.g. "Summer Sale 2026"
            $table->text('description')->nullable();

            // ── Discount ─────────────────────────────────────────────────────
            $table->enum('discount_type', ['percentage', 'fixed']);
            $table->decimal('discount_value', 10, 2);  // 20 = 20% or ₱500
            // Cap for percentage discounts (e.g. max ₱1000 off even if 20% > 1000)
            $table->decimal('max_discount_amount', 10, 2)->nullable();

            // ── Validity window (when the code can be used) ──────────────────
            $table->date('start_date');
            $table->date('end_date');

            // ── Booking window restriction ───────────────────────────────────
            // Code is only valid when the booking's check-in falls within this window.
            // e.g. "book for stays between Dec 20 and Jan 5"
            $table->date('booking_start_date')->nullable();
            $table->date('booking_end_date')->nullable();

            // ── Stay length requirements ─────────────────────────────────────
            $table->unsignedTinyInteger('min_nights')->default(1);
            $table->unsignedTinyInteger('max_nights')->nullable();   // null = no limit

            // ── Usage limits ─────────────────────────────────────────────────
            $table->unsignedInteger('usage_limit')->nullable();      // null = unlimited
            $table->unsignedTinyInteger('usage_per_user_limit')->default(1);
            $table->unsignedInteger('total_used')->default(0);

            // ── Channel restrictions ─────────────────────────────────────────
            $table->boolean('online_only')->default(false);
            $table->boolean('walk_in_only')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};