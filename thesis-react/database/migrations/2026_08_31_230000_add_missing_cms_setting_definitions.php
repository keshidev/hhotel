<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $settings = [
            ['key' => 'hero_title_line1', 'value' => 'YOUR COMFORT,', 'type' => 'text', 'group' => 'hero', 'label' => 'Hero Title Line 1'],
            ['key' => 'hero_title_line2', 'value' => 'OUR PRIORITY.', 'type' => 'text', 'group' => 'hero', 'label' => 'Hero Title Line 2'],
            ['key' => 'stats_heading', 'value' => 'BUILT FOR EFFICIENCY', 'type' => 'text', 'group' => 'stats', 'label' => 'Stats Heading'],
            ['key' => 'stats_image', 'value' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=1200&auto=format&fit=crop', 'type' => 'image', 'group' => 'stats', 'label' => 'Stats Image'],
            ['key' => 'testimonials_title', 'value' => 'TRUSTED BY OUR GUESTS.', 'type' => 'text', 'group' => 'testimonials', 'label' => 'Testimonials Title'],
            ['key' => 'location_title', 'value' => 'HOW TO GET HERE', 'type' => 'text', 'group' => 'location', 'label' => 'Location Title'],
            ['key' => 'location_description', 'value' => 'Located in the vibrant heart of Quezon City, H+ Hotel is easily accessible from major transportation hubs.', 'type' => 'text', 'group' => 'location', 'label' => 'Location Description'],
            ['key' => 'location_address1', 'value' => 'One Nenita Place 89 Road 1', 'type' => 'text', 'group' => 'location', 'label' => 'Address Line 1'],
            ['key' => 'location_address2', 'value' => 'Bagong Pagasa, Quezon City', 'type' => 'text', 'group' => 'location', 'label' => 'Address Line 2'],
            ['key' => 'location_address3', 'value' => 'Philippines', 'type' => 'text', 'group' => 'location', 'label' => 'Address Line 3'],
            ['key' => 'location_map_url', 'value' => 'https://maps.google.com/maps?q=One+Nenita+Place+89+Road+1+Bagong+Pagasa+Quezon+City+Philippines&t=&z=17&ie=UTF8&iwloc=&output=embed', 'type' => 'text', 'group' => 'location', 'label' => 'Google Maps Embed URL'],
        ];

        DB::table('cms_settings')->insertOrIgnore(array_map(
            fn (array $setting) => array_merge($setting, [
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $settings
        ));
    }

    public function down(): void
    {
        DB::table('cms_settings')->whereIn('key', [
            'hero_title_line1',
            'hero_title_line2',
            'stats_heading',
            'stats_image',
            'testimonials_title',
            'location_title',
            'location_description',
            'location_address1',
            'location_address2',
            'location_address3',
            'location_map_url',
        ])->delete();
    }
};
