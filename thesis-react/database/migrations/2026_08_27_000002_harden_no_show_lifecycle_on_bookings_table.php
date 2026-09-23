<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('no_show_marked_by')->nullable()->after('no_show_marked_at')->constrained('users')->nullOnDelete();
            $table->string('no_show_contact_method', 30)->nullable()->after('no_show_contacted');
            $table->timestamp('no_show_contacted_at')->nullable()->after('no_show_contact_method');
            $table->string('no_show_contact_outcome', 40)->nullable()->after('no_show_contacted_at');
            $table->text('no_show_contact_notes')->nullable()->after('no_show_contact_outcome');
            $table->string('no_show_cutoff_time', 5)->nullable()->after('no_show_contact_notes');
            $table->string('no_show_financial_disposition', 40)->nullable()->after('no_show_cutoff_time');
            $table->decimal('no_show_financial_amount', 12, 2)->nullable()->after('no_show_financial_disposition');
            $table->string('no_show_email_status', 20)->nullable()->after('no_show_financial_amount');
            $table->unsignedSmallInteger('no_show_email_attempts')->default(0)->after('no_show_email_status');
            $table->timestamp('no_show_email_queued_at')->nullable()->after('no_show_email_attempts');
            $table->timestamp('no_show_email_sent_at')->nullable()->after('no_show_email_queued_at');
            $table->timestamp('no_show_email_failed_at')->nullable()->after('no_show_email_sent_at');
            $table->text('no_show_email_last_error')->nullable()->after('no_show_email_failed_at');
            $table->index('no_show_email_status', 'bookings_no_show_email_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_no_show_email_status_idx');
            $table->dropConstrainedForeignId('no_show_marked_by');
            $table->dropColumn([
                'no_show_contact_method',
                'no_show_contacted_at',
                'no_show_contact_outcome',
                'no_show_contact_notes',
                'no_show_cutoff_time',
                'no_show_financial_disposition',
                'no_show_financial_amount',
                'no_show_email_status',
                'no_show_email_attempts',
                'no_show_email_queued_at',
                'no_show_email_sent_at',
                'no_show_email_failed_at',
                'no_show_email_last_error',
            ]);
        });
    }
};
