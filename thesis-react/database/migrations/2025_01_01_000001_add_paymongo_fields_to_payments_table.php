<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('notes');                          // e.g. 'paymongo'
            $table->string('provider_payment_id')->nullable()->after('provider');            // PayMongo source/intent ID
            $table->string('provider_reference')->nullable()->after('provider_payment_id'); // PayMongo reference number
            $table->string('checkout_url')->nullable()->after('provider_reference');        // redirect URL (not used for QR but good to store)
            $table->string('qr_url')->nullable()->after('checkout_url');                    // QR image URL from PayMongo
            $table->json('webhook_payload')->nullable()->after('qr_url');                   // raw webhook body for audit
            $table->decimal('paid_amount', 10, 2)->nullable()->after('webhook_payload');    // actual amount received

            $table->index('provider_payment_id');
            $table->index('provider_reference');                
            $table->index(['provider', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider_payment_id']);
            $table->dropIndex(['provider_reference']);          
            $table->dropIndex(['provider', 'payment_status']);
            $table->dropColumn([
                'provider',
                'provider_payment_id',
                'provider_reference',
                'checkout_url',
                'qr_url',
                'webhook_payload',
                'paid_amount',
            ]);
        });
    }
};