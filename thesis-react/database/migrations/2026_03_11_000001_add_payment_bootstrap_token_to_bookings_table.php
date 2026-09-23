<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_bootstrap_token_hash', 128)->nullable()->after('email_verified_at');
            $table->timestamp('payment_bootstrap_expires_at')->nullable()->after('payment_bootstrap_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'payment_bootstrap_token_hash',
                'payment_bootstrap_expires_at',
            ]);
        });
    }
};
