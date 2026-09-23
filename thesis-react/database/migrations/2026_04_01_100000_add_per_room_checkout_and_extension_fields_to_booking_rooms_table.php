<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_rooms', 'checked_out_at')) {
                $table->timestamp('checked_out_at')->nullable()->after('subtotal');
            }

            if (!Schema::hasColumn('booking_rooms', 'room_status')) {
                $table->enum('room_status', ['active', 'checked_out'])
                    ->default('active')
                    ->after('checked_out_at');
                $table->index('room_status', 'booking_rooms_room_status_idx');
            }

            if (!Schema::hasColumn('booking_rooms', 'checkout_extra_charges')) {
                $table->decimal('checkout_extra_charges', 10, 2)
                    ->nullable()
                    ->after('room_status');
            }

            if (!Schema::hasColumn('booking_rooms', 'extended_checkout')) {
                $table->dateTime('extended_checkout')
                    ->nullable()
                    ->after('checkout_extra_charges');
            }

            if (!Schema::hasColumn('booking_rooms', 'extension_charge')) {
                $table->decimal('extension_charge', 10, 2)
                    ->nullable()
                    ->after('extended_checkout');
            }

            if (!Schema::hasColumn('booking_rooms', 'extension_approved_at')) {
                $table->timestamp('extension_approved_at')
                    ->nullable()
                    ->after('extension_charge');
            }

            if (!Schema::hasColumn('booking_rooms', 'extension_approved_by')) {
                $table->foreignId('extension_approved_by')
                    ->nullable()
                    ->after('extension_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index('extension_approved_by', 'booking_rooms_extension_approved_by_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            if (Schema::hasColumn('booking_rooms', 'extension_approved_by')) {
                $table->dropConstrainedForeignId('extension_approved_by');
            }

            if (Schema::hasColumn('booking_rooms', 'extension_approved_at')) {
                $table->dropColumn('extension_approved_at');
            }

            if (Schema::hasColumn('booking_rooms', 'extension_charge')) {
                $table->dropColumn('extension_charge');
            }

            if (Schema::hasColumn('booking_rooms', 'extended_checkout')) {
                $table->dropColumn('extended_checkout');
            }

            if (Schema::hasColumn('booking_rooms', 'checkout_extra_charges')) {
                $table->dropColumn('checkout_extra_charges');
            }

            if (Schema::hasColumn('booking_rooms', 'room_status')) {
                $table->dropIndex('booking_rooms_room_status_idx');
                $table->dropColumn('room_status');
            }

            if (Schema::hasColumn('booking_rooms', 'checked_out_at')) {
                $table->dropColumn('checked_out_at');
            }
        });
    }
};

