<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('phone');
            $table->string('address_line_1')->nullable()->after('country_code');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city', 120)->nullable()->after('address_line_2');
            $table->string('postal_code', 20)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->dropColumn([
                'country_code',
                'address_line_1',
                'address_line_2',
                'city',
                'postal_code',
            ]);
        });
    }
};
