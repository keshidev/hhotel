<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('confirmation_email_status', 20)->nullable()->after('email_verified_at');
            $table->unsignedSmallInteger('confirmation_email_attempts')->default(0)->after('confirmation_email_status');
            $table->timestamp('confirmation_email_queued_at')->nullable()->after('confirmation_email_attempts');
            $table->timestamp('confirmation_email_sent_at')->nullable()->after('confirmation_email_queued_at');
            $table->timestamp('confirmation_email_failed_at')->nullable()->after('confirmation_email_sent_at');
            $table->text('confirmation_email_last_error')->nullable()->after('confirmation_email_failed_at');
            $table->index('confirmation_email_status', 'bookings_confirmation_email_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_confirmation_email_status_index');
            $table->dropColumn([
                'confirmation_email_status',
                'confirmation_email_attempts',
                'confirmation_email_queued_at',
                'confirmation_email_sent_at',
                'confirmation_email_failed_at',
                'confirmation_email_last_error',
            ]);
        });
    }
};
