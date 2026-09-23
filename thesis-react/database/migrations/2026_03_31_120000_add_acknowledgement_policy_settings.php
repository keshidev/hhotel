<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $defaults = [
            [
                'key' => 'policy_privacy_terms',
                'value' => 'We collect guest information such as your name, email, contact number, and booking details to process reservations, payments, and support requests. We use this data only for reservation management, guest communication, compliance, and service improvements. Your information is protected with appropriate administrative and technical safeguards, and we do not sell personal data. If you have questions or concerns about your data, please contact our support team through the hotel contact details provided on this website.',
                'type' => 'text',
                'group' => 'policies',
                'label' => 'Privacy Terms',
            ],
            [
                'key' => 'policy_booking_conditions',
                'value' => 'Reservations are confirmed only after required payment and verification steps are completed. Published rates, inclusions, and availability are subject to validation at the time of booking. Cancellation, rebooking, and no-show handling follow the active hotel policy shown during booking and in confirmation communications. Check-in and check-out schedules must be observed, and guests are required to present a valid government-issued ID upon arrival.',
                'type' => 'text',
                'group' => 'policies',
                'label' => 'Booking Conditions',
            ],
        ];

        foreach ($defaults as $setting) {
            DB::table('cms_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, [
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        DB::table('cms_settings')
            ->whereIn('key', ['policy_privacy_terms', 'policy_booking_conditions'])
            ->delete();
    }
};
