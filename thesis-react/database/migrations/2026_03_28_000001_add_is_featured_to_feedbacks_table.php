<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('feedbacks')) {
            return;
        }

        if (!Schema::hasColumn('feedbacks', 'is_featured')) {
            Schema::table('feedbacks', function (Blueprint $table) {
                $table->boolean('is_featured')->default(false)->after('is_submitted');
                $table->index('is_featured');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('feedbacks') || !Schema::hasColumn('feedbacks', 'is_featured')) {
            return;
        }

        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropIndex(['is_featured']);
            $table->dropColumn('is_featured');
        });
    }
};

