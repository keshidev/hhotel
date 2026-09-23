<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cms_settings')
            ->whereIn('key', ['intro_title', 'intro_description'])
            ->delete();

        $now = now();

        $json = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $defaults = [
            [
                'key' => 'hero_primary_button_text',
                'value' => 'BOOK YOUR STAY',
                'type' => 'text',
                'group' => 'hero',
                'label' => 'Hero Primary Button Text',
            ],
            [
                'key' => 'hero_primary_button_link',
                'value' => '/select-room',
                'type' => 'text',
                'group' => 'hero',
                'label' => 'Hero Primary Button Link',
            ],
            [
                'key' => 'hero_secondary_button_text',
                'value' => 'EXPLORE ROOMS',
                'type' => 'text',
                'group' => 'hero',
                'label' => 'Hero Secondary Button Text',
            ],
            [
                'key' => 'hero_secondary_button_link',
                'value' => '/rooms',
                'type' => 'text',
                'group' => 'hero',
                'label' => 'Hero Secondary Button Link',
            ],
            [
                'key' => 'highlights_items',
                'value' => $json([
                    ['icon' => 'calendar-check', 'title' => 'FAST BOOKING', 'description' => 'Reserve your room in under 2 minutes with our streamlined booking system.'],
                    ['icon' => 'receipt', 'title' => 'CLEAR BILLING', 'description' => 'Transparent pricing with no hidden fees and secure payments.'],
                    ['icon' => 'bed-double', 'title' => 'COMFORTABLE ROOMS', 'description' => 'Every room is equipped for a restful stay with premium essentials.'],
                    ['icon' => 'map-pin', 'title' => 'PRIME LOCATION', 'description' => 'Minutes from key destinations in Quezon City.'],
                ]),
                'type' => 'json',
                'group' => 'highlights',
                'label' => 'Highlights Items',
            ],
            [
                'key' => 'stats_items',
                'value' => $json([
                    ['value' => '2min', 'label' => 'AVERAGE BOOKING TIME'],
                    ['value' => '24/7', 'label' => 'FRONT DESK SUPPORT'],
                    ['value' => '100%', 'label' => 'TRANSPARENT BILLING'],
                    ['value' => '6+', 'label' => 'ROOM TYPES AVAILABLE'],
                ]),
                'type' => 'json',
                'group' => 'stats',
                'label' => 'Stats Items',
            ],
            [
                'key' => 'testimonials_items',
                'value' => $json([
                    ['guest_name' => 'Maria Santos', 'review_text' => 'Smooth booking and very clear billing from start to finish.', 'star_rating' => 5, 'date' => '2026-01-20', 'is_active' => true],
                    ['guest_name' => 'James Reyes', 'review_text' => 'Great staff and clean rooms. We enjoyed every part of our stay.', 'star_rating' => 5, 'date' => '2026-02-14', 'is_active' => true],
                    ['guest_name' => 'Anna Dela Cruz', 'review_text' => 'Reliable hotel for business trips with fast check-in.', 'star_rating' => 4, 'date' => '2026-03-01', 'is_active' => true],
                ]),
                'type' => 'json',
                'group' => 'testimonials',
                'label' => 'Testimonials Items',
            ],
            [
                'key' => 'policies_items',
                'value' => $json([
                    ['title' => 'Check-In Time', 'body' => 'After 3:00 PM'],
                    ['title' => 'Check-Out Time', 'body' => 'Before 12:00 PM'],
                    ['title' => 'Down Payment Policy', 'body' => 'Down payment is required to confirm the reservation.'],
                    ['title' => 'Cancellation Policy', 'body' => 'If cancellation is requested within 24 hours of check-in time, it is non-refundable.'],
                    ['title' => 'Request Limit', 'body' => 'Guests may submit only one cancellation request and one rebooking request.'],
                    ['title' => 'Rebooking Policy', 'body' => 'Rebooking requests may have rate differences and require staff approval.'],
                    ['title' => 'ID Requirement', 'body' => 'Please present a valid government-issued ID during check-in.'],
                ]),
                'type' => 'json',
                'group' => 'policies',
                'label' => 'Policies Items',
            ],
            [
                'key' => 'location_contact_number',
                'value' => '+63 917 809 9482',
                'type' => 'text',
                'group' => 'location',
                'label' => 'Location Contact Number',
            ],
            [
                'key' => 'location_contact_email',
                'value' => 'hhotelsph@gmail.com',
                'type' => 'text',
                'group' => 'location',
                'label' => 'Location Contact Email',
            ],
            [
                'key' => 'room_executive_suite_image',
                'value' => '',
                'type' => 'image',
                'group' => 'room_types',
                'label' => 'Executive Suite Website Image',
            ],
            [
                'key' => 'room_family_image',
                'value' => '',
                'type' => 'image',
                'group' => 'room_types',
                'label' => 'Family Room Website Image',
            ],
            [
                'key' => 'room_deluxe_image',
                'value' => '',
                'type' => 'image',
                'group' => 'room_types',
                'label' => 'Deluxe Website Image',
            ],
            [
                'key' => 'room_superior_twin_image',
                'value' => '',
                'type' => 'image',
                'group' => 'room_types',
                'label' => 'Superior Twin Website Image',
            ],
            [
                'key' => 'room_superior_queen_image',
                'value' => '',
                'type' => 'image',
                'group' => 'room_types',
                'label' => 'Superior Queen Website Image',
            ],
            [
                'key' => 'room_premier_image',
                'value' => '',
                'type' => 'image',
                'group' => 'room_types',
                'label' => 'Premier Website Image',
            ],
            [
                'key' => 'addons_items',
                'value' => $json([
                    ['id' => 'rollaway_bed', 'name' => 'Rollaway Bed', 'display_description' => 'Add extra sleeping space for a more restful stay.', 'image' => 'https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=560&h=360&fit=crop'],
                    ['id' => 'extra_pillows_and_blankets', 'name' => 'Extra Pillows and Blankets', 'display_description' => 'Make your room feel even more relaxing with extra pillows and blankets.', 'image' => 'https://images.unsplash.com/photo-1616628182509-6f0a8a7f3f5a?w=560&h=360&fit=crop'],
                    ['id' => 'breakfast_package', 'name' => 'Breakfast Package', 'display_description' => 'Enjoy a convenient and flavorful start before your plans for the day.', 'image' => 'https://images.unsplash.com/photo-1525351484163-7529414344d8?w=560&h=360&fit=crop'],
                    ['id' => 'early_check_in', 'name' => 'Early Check-In', 'display_description' => 'Settle into your room sooner and enjoy more time to relax.', 'image' => 'https://images.unsplash.com/photo-1590490360182-c33d57733427?w=560&h=360&fit=crop'],
                    ['id' => 'late_check_out', 'name' => 'Late Check-Out', 'display_description' => 'Extend your departure and enjoy a more flexible final day.', 'image' => 'https://images.unsplash.com/photo-1455587734955-081b22074882?w=560&h=360&fit=crop'],
                    ['id' => 'extra_toiletries_kit', 'name' => 'Extra Toiletries Kit', 'display_description' => 'Enjoy added essentials for a more convenient and comfortable stay.', 'image' => 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?w=560&h=360&fit=crop'],
                    ['id' => 'laundry_service', 'name' => 'Laundry Service', 'display_description' => 'Keep your wardrobe fresh throughout your stay with our laundry service.', 'image' => 'https://images.unsplash.com/photo-1626806787461-102c1a0f4f79?w=560&h=360&fit=crop'],
                ]),
                'type' => 'json',
                'group' => 'addons',
                'label' => 'Add-ons Display Items',
            ],
            [
                'key' => 'brand_primary_color',
                'value' => '#1a4bcc',
                'type' => 'text',
                'group' => 'brand',
                'label' => 'Brand Primary Color',
            ],
            [
                'key' => 'brand_accent_color',
                'value' => '#0d1b3e',
                'type' => 'text',
                'group' => 'brand',
                'label' => 'Brand Accent Color',
            ],
            [
                'key' => 'brand_button_color',
                'value' => '#1a4bcc',
                'type' => 'text',
                'group' => 'brand',
                'label' => 'Brand Button Color',
            ],
            [
                'key' => 'brand_button_hover_color',
                'value' => '#1340b8',
                'type' => 'text',
                'group' => 'brand',
                'label' => 'Brand Button Hover Color',
            ],
        ];

        foreach ($defaults as $setting) {
            DB::table('cms_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['updated_at' => $now, 'created_at' => $now])
            );
        }
    }

    public function down(): void
    {
        DB::table('cms_settings')->whereIn('key', [
            'hero_primary_button_text',
            'hero_primary_button_link',
            'hero_secondary_button_text',
            'hero_secondary_button_link',
            'highlights_items',
            'stats_items',
            'testimonials_items',
            'policies_items',
            'location_contact_number',
            'location_contact_email',
            'room_executive_suite_image',
            'room_family_image',
            'room_deluxe_image',
            'room_superior_twin_image',
            'room_superior_queen_image',
            'room_premier_image',
            'addons_items',
            'brand_primary_color',
            'brand_accent_color',
            'brand_button_color',
            'brand_button_hover_color',
        ])->delete();

        $now = now();
        DB::table('cms_settings')->updateOrInsert(
            ['key' => 'intro_title'],
            [
                'value' => 'A Good Place to Stay for Your Business Trips',
                'type' => 'text',
                'group' => 'intro',
                'label' => 'Intro Title',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
        DB::table('cms_settings')->updateOrInsert(
            ['key' => 'intro_description'],
            [
                'value' => 'Experience comfort and convenience in the heart of the city.',
                'type' => 'text',
                'group' => 'intro',
                'label' => 'Intro Description',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }
};

