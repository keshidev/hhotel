<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 24)->unique();
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->string('booking_reference', 40)->nullable()->index();
            $table->string('subject', 30);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->char('submission_key', 64)->unique();
            $table->char('source_ip_hash', 64)->nullable();
            $table->string('customer_email_status', 20)->default('pending');
            $table->timestamp('customer_email_sent_at')->nullable();
            $table->string('staff_notification_status', 20)->default('pending');
            $table->timestamp('staff_notification_sent_at')->nullable();
            $table->text('notification_error')->nullable();
            $table->timestamp('anonymized_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
