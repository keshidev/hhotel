<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');

            // Ratings 1–5
            $table->unsignedTinyInteger('rating_cleanliness')->nullable();
            $table->unsignedTinyInteger('rating_comfort')->nullable();
            $table->unsignedTinyInteger('rating_staff')->nullable();
            $table->unsignedTinyInteger('rating_facilities')->nullable();
            $table->unsignedTinyInteger('rating_overall')->nullable();

            // Review text
            $table->text('review')->nullable();

            // Issue report
            $table->boolean('has_issue')->default(false);
            $table->string('issue_type')->nullable();

            // Recommendation
            $table->boolean('would_recommend')->nullable();

            // Token for one-time tokenized access (no login needed)
            $table->string('token', 64)->unique()->nullable();
            $table->boolean('is_submitted')->default(false);
            $table->timestamp('submitted_at')->nullable();

            // Admin reply
            $table->text('admin_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
