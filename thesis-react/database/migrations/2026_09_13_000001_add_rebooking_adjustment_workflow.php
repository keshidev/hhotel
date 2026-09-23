<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rebookings MODIFY status ENUM('pending','under_review','approved','awaiting_payment','rejected','cancelled','completed','closed') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'purpose')) {
                $table->string('purpose', 40)->default('booking')->after('payment_type');
                $table->index(['purpose', 'payment_status'], 'payments_purpose_status_idx');
            }
            if (! Schema::hasColumn('payments', 'rebooking_id')) {
                $table->foreignId('rebooking_id')->nullable()->after('booking_id')
                    ->constrained('rebookings')->nullOnDelete();
                $table->index(['rebooking_id', 'payment_status'], 'payments_rebooking_status_idx');
            }
        });

        $addFinancialStatus = ! Schema::hasColumn('rebookings', 'financial_status');
        Schema::table('rebookings', function (Blueprint $table) use ($addFinancialStatus) {
            if ($addFinancialStatus) {
                $table->string('financial_status', 40)->default('unassessed')->after('status');
                $table->index(['financial_status', 'created_at'], 'rebookings_financial_status_idx');
            }
            if (! Schema::hasColumn('rebookings', 'original_total')) {
                $table->decimal('original_total', 12, 2)->nullable()->after('financial_status');
            }
            if (! Schema::hasColumn('rebookings', 'projected_total')) {
                $table->decimal('projected_total', 12, 2)->nullable()->after('original_total');
            }
            if (! Schema::hasColumn('rebookings', 'price_difference')) {
                $table->decimal('price_difference', 12, 2)->nullable()->after('projected_total');
            }
            if (! Schema::hasColumn('rebookings', 'adjustment_amount')) {
                $table->decimal('adjustment_amount', 12, 2)->nullable()->after('price_difference');
            }
            if (! Schema::hasColumn('rebookings', 'adjustment_payment_id')) {
                $table->foreignId('adjustment_payment_id')->nullable()->after('adjustment_amount')
                    ->constrained('payments')->nullOnDelete();
            }
            if (! Schema::hasColumn('rebookings', 'quote_expires_at')) {
                $table->timestamp('quote_expires_at')->nullable()->after('adjustment_payment_id');
            }
            if (! Schema::hasColumn('rebookings', 'refund_processed_at')) {
                $table->timestamp('refund_processed_at')->nullable()->after('quote_expires_at');
            }
        });

        if (! Schema::hasTable('rebooking_room_holds')) {
            Schema::create('rebooking_room_holds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rebooking_id')->unique()->constrained('rebookings')->cascadeOnDelete();
                $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
                $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                $table->date('check_in');
                $table->date('check_out');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
                $table->index(['room_id', 'released_at', 'expires_at'], 'rebooking_room_hold_active_idx');
            });
        }

        if (! Schema::hasTable('rebooking_refunds')) {
            Schema::create('rebooking_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rebooking_id')->unique()->constrained('rebookings')->cascadeOnDelete();
                $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
                $table->foreignId('source_payment_id')->constrained('payments')->restrictOnDelete();
                $table->foreignId('refund_payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('status', 24)->default('completed');
                $table->string('recipient_name', 120);
                $table->text('recipient_account');
                $table->string('recipient_account_last_four', 4);
                $table->string('gcash_reference', 80);
                $table->string('normalized_gcash_reference', 80)->unique();
                $table->timestamp('processed_at');
                $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason');
                $table->string('proof_disk', 40);
                $table->string('proof_path');
                $table->string('proof_original_name');
                $table->string('proof_mime_type', 100);
                $table->unsignedBigInteger('proof_size');
                $table->char('proof_sha256', 64);
                $table->timestamp('proof_retention_expires_at')->nullable();
                $table->timestamp('proof_deleted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rebooking_refunds');
        Schema::dropIfExists('rebooking_room_holds');

        Schema::table('rebookings', function (Blueprint $table) {
            $table->dropIndex('rebookings_financial_status_idx');
            $table->dropConstrainedForeignId('adjustment_payment_id');
            $table->dropColumn([
                'financial_status', 'original_total', 'projected_total', 'price_difference',
                'adjustment_amount', 'quote_expires_at', 'refund_processed_at',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_rebooking_status_idx');
            $table->dropConstrainedForeignId('rebooking_id');
            $table->dropIndex('payments_purpose_status_idx');
            $table->dropColumn('purpose');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('rebookings')->whereIn('status', ['under_review', 'awaiting_payment'])->update(['status' => 'pending']);
            DB::table('rebookings')->whereIn('status', ['cancelled', 'closed'])->update(['status' => 'rejected']);
            DB::table('rebookings')->where('status', 'completed')->update(['status' => 'approved']);
            DB::statement("ALTER TABLE rebookings MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
