<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $nearbyItems = [
            ['name' => 'SM North EDSA', 'category' => 'Shopping & Dining', 'description' => 'A major Quezon City destination for shopping, dining, and entertainment.', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=SM+North+EDSA', 'image' => ''],
            ['name' => 'TriNoma', 'category' => 'Shopping & Transit', 'description' => 'Shopping and dining with convenient access to nearby transport connections.', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=TriNoma+Quezon+City', 'image' => ''],
            ['name' => 'Solaire Resort North', 'category' => 'Entertainment', 'description' => 'A Quezon City destination for dining, events, and entertainment.', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=Solaire+Resort+North', 'image' => ''],
            ['name' => 'Quezon Memorial Circle', 'category' => 'City Landmark', 'description' => 'A landmark park with green spaces, museums, and recreational attractions.', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=Quezon+Memorial+Circle', 'image' => ''],
        ];
        $galleryItems = [
            ['image' => '/images/Executive%20Suite/executive_room.jpg', 'title' => 'Executive Suite', 'alt' => 'Executive Suite sleeping area'],
            ['image' => '/images/Deluxe%20Room/deluxe_room.jpg', 'title' => 'Deluxe Room', 'alt' => 'Deluxe Room interior'],
            ['image' => '/images/Superior%20Twin/superior_twin_room.jpg', 'title' => 'Superior Twin', 'alt' => 'Superior Twin room interior'],
            ['image' => '/images/Superior%20Queen/superior_queen_2.jpg', 'title' => 'Superior Queen', 'alt' => 'Superior Queen room interior'],
            ['image' => '/images/Premier%20Room/premier_room.jpg', 'title' => 'Premier Room', 'alt' => 'Premier Room interior'],
        ];
        $settings = [
            ['key' => 'nearby_title', 'value' => 'EXPLORE THE NEIGHBORHOOD', 'type' => 'text', 'group' => 'nearby', 'label' => 'Nearby Places Title'],
            ['key' => 'nearby_description', 'value' => 'Shopping, dining, entertainment, and city landmarks are within easy reach of H+ Hotel.', 'type' => 'text', 'group' => 'nearby', 'label' => 'Nearby Places Description'],
            ['key' => 'nearby_items', 'value' => json_encode($nearbyItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'type' => 'json', 'group' => 'nearby', 'label' => 'Nearby Places'],
            ['key' => 'gallery_title', 'value' => 'A CLOSER LOOK', 'type' => 'text', 'group' => 'gallery', 'label' => 'Gallery Title'],
            ['key' => 'gallery_description', 'value' => 'Step inside our rooms and discover the comfortable details that make every stay feel effortless.', 'type' => 'text', 'group' => 'gallery', 'label' => 'Gallery Description'],
            ['key' => 'gallery_items', 'value' => json_encode($galleryItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'type' => 'json', 'group' => 'gallery', 'label' => 'Gallery Images'],
        ];

        DB::table('cms_settings')->insertOrIgnore(array_map(
            fn (array $setting) => array_merge($setting, ['created_at' => $now, 'updated_at' => $now]),
            $settings
        ));
    }

    public function down(): void
    {
        DB::table('cms_settings')->whereIn('key', [
            'nearby_title',
            'nearby_description',
            'nearby_items',
            'gallery_title',
            'gallery_description',
            'gallery_items',
        ])->delete();
    }
};
