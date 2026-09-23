<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Who
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('user_staff_name')->nullable();

            // What
            $table->string('action');
            $table->string('action_activity')->nullable();

            // Where
            $table->string('model_type');
            $table->unsignedBigInteger('model_id')->default(0);
            $table->string('module_page')->nullable();
            $table->string('record_affected')->nullable();

            // Change payload
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Meta
            $table->string('ip_address')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['model_type', 'model_id']);
            $table->index('action_activity');
            $table->index('module_page');
            $table->index('user_staff_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};