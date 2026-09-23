<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table) {
            $table->text('resolution_message')->nullable()->after('resolved_at');
            $table->string('resolution_email_status', 20)->default('not_sent')->after('resolution_message');
            $table->unsignedInteger('resolution_email_version')->default(0)->after('resolution_email_status');
            $table->timestamp('resolution_email_sent_at')->nullable()->after('resolution_email_version');
            $table->text('resolution_email_error')->nullable()->after('resolution_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table) {
            $table->dropColumn([
                'resolution_message',
                'resolution_email_status',
                'resolution_email_version',
                'resolution_email_sent_at',
                'resolution_email_error',
            ]);
        });
    }
};
