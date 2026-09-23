<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cancellation_approval_requests', function (Blueprint $table) {
            $table->text('refund_recipient_name')->nullable();
            $table->text('refund_recipient_account')->nullable();
            $table->timestamp('refund_recipient_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cancellation_approval_requests', function (Blueprint $table) {
            $table->dropColumn(['refund_recipient_name', 'refund_recipient_account', 'refund_recipient_confirmed_at']);
        });
    }
};
