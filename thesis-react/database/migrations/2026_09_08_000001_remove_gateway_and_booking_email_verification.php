<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'provider')) {
            $legacyPaymentUpdates = [
                'provider' => 'legacy_online_gcash',
                'payment_method' => 'gcash',
            ];
            foreach ([
                'checkout_url',
                'qr_url',
                'webhook_payload',
                'provider_operation_token',
                'provider_operation_started_at',
                'provider_operation_failed_at',
                'provider_operation_error',
            ] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $legacyPaymentUpdates[$column] = null;
                }
            }

            DB::table('payments')
                ->where('provider', 'paymongo')
                ->update($legacyPaymentUpdates);

            $retiredIndexes = [
                'payments_provider_payment_id_index',
                'payments_provider_source_unique',
                'payments_provider_operation_status_index',
            ];
            $existingIndexes = collect(Schema::getIndexes('payments'))->pluck('name')->all();
            Schema::table('payments', function (Blueprint $table) use ($retiredIndexes, $existingIndexes) {
                foreach ($retiredIndexes as $index) {
                    if (in_array($index, $existingIndexes, true)) {
                        $table->dropIndex($index);
                    }
                }
            });

            $retiredColumns = array_values(array_filter([
                'provider_payment_id',
                'checkout_url',
                'qr_url',
                'webhook_payload',
                'provider_operation_status',
                'provider_operation_token',
                'provider_operation_started_at',
                'provider_operation_failed_at',
                'provider_operation_error',
            ], fn (string $column): bool => Schema::hasColumn('payments', $column)));
            if ($retiredColumns !== []) {
                Schema::table('payments', function (Blueprint $table) use ($retiredColumns) {
                    $table->dropColumn($retiredColumns);
                });
            }
        }

        if (Schema::hasTable('bookings')) {
            DB::table('bookings')
                ->where('booking_status', 'pending')
                ->whereIn('reservation_status', ['pending_verification', 'email_verified'])
                ->update(['reservation_status' => 'pending_payment']);

            $verificationColumns = array_values(array_filter([
                'email_verification_token',
                'email_verification_expires_at',
                'email_verified_at',
            ], fn (string $column): bool => Schema::hasColumn('bookings', $column)));

            if ($verificationColumns !== []) {
                Schema::table('bookings', function (Blueprint $table) use ($verificationColumns) {
                    $table->dropColumn($verificationColumns);
                });
            }

            if (Schema::hasColumn('bookings', 'reservation_status')) {
                Schema::table('bookings', function (Blueprint $table) {
                    $table->string('reservation_status', 32)->default('pending_payment')->change();
                });
            }
        }

        foreach (['jobs', 'failed_jobs'] as $queueTable) {
            if (Schema::hasTable($queueTable) && Schema::hasColumn($queueTable, 'payload')) {
                DB::table($queueTable)
                    ->where('payload', 'like', '%BookingEmailVerification%')
                    ->delete();
            }
        }

        Schema::dropIfExists('webhook_events');
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'provider_payment_id')) {
                    $table->string('provider_payment_id')->nullable()->after('provider');
                }
                if (! Schema::hasColumn('payments', 'checkout_url')) {
                    $table->string('checkout_url')->nullable()->after('provider_reference');
                }
                if (! Schema::hasColumn('payments', 'qr_url')) {
                    $table->string('qr_url')->nullable()->after('checkout_url');
                }
                if (! Schema::hasColumn('payments', 'webhook_payload')) {
                    $table->json('webhook_payload')->nullable()->after('qr_url');
                }
                if (! Schema::hasColumn('payments', 'provider_operation_status')) {
                    $table->string('provider_operation_status', 40)->nullable()->after('provider_reference');
                }
                if (! Schema::hasColumn('payments', 'provider_operation_token')) {
                    $table->string('provider_operation_token', 64)->nullable()->after('provider_operation_status');
                }
                if (! Schema::hasColumn('payments', 'provider_operation_started_at')) {
                    $table->timestamp('provider_operation_started_at')->nullable()->after('provider_operation_token');
                }
                if (! Schema::hasColumn('payments', 'provider_operation_failed_at')) {
                    $table->timestamp('provider_operation_failed_at')->nullable()->after('provider_operation_started_at');
                }
                if (! Schema::hasColumn('payments', 'provider_operation_error')) {
                    $table->text('provider_operation_error')->nullable()->after('provider_operation_failed_at');
                }
            });

            $existingIndexes = collect(Schema::getIndexes('payments'))->pluck('name')->all();
            Schema::table('payments', function (Blueprint $table) use ($existingIndexes) {
                if (! in_array('payments_provider_payment_id_index', $existingIndexes, true)) {
                    $table->index('provider_payment_id');
                }
                if (! in_array('payments_provider_source_unique', $existingIndexes, true)) {
                    $table->unique(['provider', 'provider_payment_id'], 'payments_provider_source_unique');
                }
                if (! in_array('payments_provider_operation_status_index', $existingIndexes, true)) {
                    $table->index('provider_operation_status', 'payments_provider_operation_status_index');
                }
            });
        }

        if (Schema::hasTable('bookings')) {
            if (Schema::hasColumn('bookings', 'reservation_status')) {
                Schema::table('bookings', function (Blueprint $table) {
                    $table->string('reservation_status', 32)->default('pending_verification')->change();
                });
            }

            Schema::table('bookings', function (Blueprint $table) {
                if (! Schema::hasColumn('bookings', 'email_verification_token')) {
                    $table->string('email_verification_token', 128)->nullable()->after('reservation_status');
                }
                if (! Schema::hasColumn('bookings', 'email_verification_expires_at')) {
                    $table->timestamp('email_verification_expires_at')->nullable()->after('email_verification_token');
                }
                if (! Schema::hasColumn('bookings', 'email_verified_at')) {
                    $table->timestamp('email_verified_at')->nullable()->after('email_verification_expires_at');
                }
            });
        }

        if (! Schema::hasTable('webhook_events')) {
            Schema::create('webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 40);
                $table->string('event_id', 191);
                $table->string('event_type', 120)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->string('status', 20)->default('received');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('processing_started_at')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'event_id']);
                $table->index(['provider', 'event_type']);
                $table->index(['provider', 'status']);
            });
        }
    }
};
