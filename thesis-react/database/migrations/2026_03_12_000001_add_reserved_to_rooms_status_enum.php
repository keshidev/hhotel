<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\RoomStateService;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('available','reserved','occupied','maintenance','cleaning') NOT NULL DEFAULT 'available'");
        }

        $roomIds = DB::table('rooms')->pluck('id');
        $service = app(RoomStateService::class);

        foreach ($roomIds as $roomId) {
            $service->recalculate((int) $roomId);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::table('rooms')->where('status', 'reserved')->update(['status' => 'available']);
            DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('available','occupied','maintenance','cleaning') NOT NULL DEFAULT 'available'");
        }
    }
};
