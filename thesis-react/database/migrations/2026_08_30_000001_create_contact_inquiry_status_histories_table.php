<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_inquiry_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_inquiry_id')->constrained('contact_inquiries')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 30)->nullable();
            $table->timestamps();

            $table->index(['contact_inquiry_id', 'created_at'], 'contact_inquiry_history_timeline_index');
        });

        DB::table('contact_inquiries')
            ->select(['id', 'status', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(200, function ($inquiries) {
                $rows = [];
                foreach ($inquiries as $inquiry) {
                    $rows[] = [
                        'contact_inquiry_id' => $inquiry->id,
                        'from_status' => null,
                        'to_status' => $inquiry->status,
                        'reason' => 'Existing inquiry status imported during workflow hardening.',
                        'changed_by' => null,
                        'actor_role' => 'system',
                        'created_at' => $inquiry->created_at ?? now(),
                        'updated_at' => $inquiry->updated_at ?? now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('contact_inquiry_status_histories')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiry_status_histories');
    }
};
