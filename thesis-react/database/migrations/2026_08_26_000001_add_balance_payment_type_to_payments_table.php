<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_type', 32)->default('downpayment')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE payments MODIFY payment_type ENUM('downpayment', 'full_payment', 'balance_payment', 'refund') NOT NULL DEFAULT 'downpayment'"
        );
    }

    public function down(): void
    {
        DB::table('payments')
            ->where('payment_type', 'balance_payment')
            ->update(['payment_type' => 'full_payment']);

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('payments', function (Blueprint $table) {
                $table->enum('payment_type', ['downpayment', 'full_payment', 'refund'])
                    ->default('downpayment')
                    ->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE payments MODIFY payment_type ENUM('downpayment', 'full_payment', 'refund') NOT NULL DEFAULT 'downpayment'"
        );
    }
};
