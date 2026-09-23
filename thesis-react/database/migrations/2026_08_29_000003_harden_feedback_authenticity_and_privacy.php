<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->timestamp('token_expires_at')->nullable()->after('token');
            $table->index('token_expires_at', 'feedbacks_token_expires_at_index');
        });

        $lifetimeDays = max(1, (int) config('feedback.token_lifetime_days', 30));
        DB::table('feedbacks')
            ->select(['id', 'created_at', 'submitted_at'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($lifetimeDays) {
                foreach ($rows as $row) {
                    $base = $row->submitted_at ?: $row->created_at ?: now();
                    DB::table('feedbacks')->where('id', $row->id)->update([
                        'token_expires_at' => Carbon::parse($base)->addDays($lifetimeDays),
                    ]);
                }
            });

        $duplicateBookingIds = DB::table('feedbacks')
            ->select('booking_id')
            ->groupBy('booking_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('booking_id');

        foreach ($duplicateBookingIds as $bookingId) {
            $rows = DB::table('feedbacks')->where('booking_id', $bookingId)->get();
            $keeper = $rows
                ->sortByDesc(fn ($row) => sprintf(
                    '%d|%s|%020d',
                    (int) $row->is_submitted,
                    (string) ($row->submitted_at ?? ''),
                    (int) $row->id
                ))
                ->first();

            if ($keeper) {
                DB::table('feedbacks')
                    ->where('booking_id', $bookingId)
                    ->where('id', '!=', $keeper->id)
                    ->delete();
            }
        }

        Schema::table('feedbacks', function (Blueprint $table) {
            $table->unique('booking_id', 'feedbacks_booking_id_unique');
        });

        $setting = DB::table('cms_settings')->where('key', 'testimonials_items')->first();
        if ($setting) {
            $items = json_decode((string) $setting->value, true);
            if (is_array($items)) {
                foreach ($items as &$item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if ((string) ($item['source'] ?? '') !== 'guest_feedback' && (int) ($item['feedback_id'] ?? 0) <= 0) {
                        continue;
                    }

                    $parts = preg_split('/\s+/u', trim((string) ($item['guest_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $masked = collect($parts)
                        ->take(3)
                        ->map(fn ($part) => mb_strtoupper(mb_substr((string) $part, 0, 1)) . '.')
                        ->filter(fn ($part) => $part !== '.')
                        ->implode(' ');
                    $item['guest_name'] = $masked !== '' ? $masked : 'Verified Guest';
                }
                unset($item);

                DB::table('cms_settings')->where('id', $setting->id)->update([
                    'value' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropUnique('feedbacks_booking_id_unique');
            $table->dropIndex('feedbacks_token_expires_at_index');
            $table->dropColumn('token_expires_at');
        });
    }
};
