<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_charges', function (Blueprint $table) {
            $table->uuid('operation_token')->nullable()->after('created_by');
            $table->unsignedSmallInteger('operation_line')->nullable()->after('operation_token');
            $table->char('operation_hash', 64)->nullable()->after('operation_line');
            $table->unique(
                ['booking_id', 'operation_token', 'operation_line'],
                'booking_charge_operation_line_unique'
            );
            $table->index(
                ['booking_id', 'operation_token'],
                'booking_charge_operation_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('booking_charges', function (Blueprint $table) {
            $table->dropUnique('booking_charge_operation_line_unique');
            $table->dropIndex('booking_charge_operation_lookup');
            $table->dropColumn(['operation_token', 'operation_line', 'operation_hash']);
        });
    }
};
