<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $settings = [
            [
                'key' => 'about_eyebrow',
                'value' => 'ABOUT H+ HOTEL',
                'type' => 'text',
                'group' => 'about',
                'label' => 'About Section Label',
            ],
            [
                'key' => 'about_title',
                'value' => 'A STAY BUILT AROUND COMFORT.',
                'type' => 'text',
                'group' => 'about',
                'label' => 'About Heading',
            ],
            [
                'key' => 'about_description',
                'value' => 'H+ Hotel QC offers clean, cozy, and thoughtfully prepared rooms for guests who value comfort, convenience, and straightforward service.',
                'type' => 'text',
                'group' => 'about',
                'label' => 'About Introduction',
            ],
            [
                'key' => 'about_secondary_text',
                'value' => 'Located near key shopping, dining, and entertainment destinations in Quezon City, we make it easy to settle in, recharge, and enjoy the city at your own pace.',
                'type' => 'text',
                'group' => 'about',
                'label' => 'About Supporting Description',
            ],
            [
                'key' => 'about_image',
                'value' => '/images/Executive%20Suite/executive_2.jpg',
                'type' => 'image',
                'group' => 'about',
                'label' => 'About Image',
            ],
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
            'about_eyebrow',
            'about_title',
            'about_description',
            'about_secondary_text',
            'about_image',
        ])->delete();
    }
};
