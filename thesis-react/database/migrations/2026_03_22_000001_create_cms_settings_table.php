<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->string('type')->default('text'); // text | image | json | boolean
            $table->string('group')->default('general'); // hero | policies | room_types | intro
            $table->string('label')->nullable(); // human-readable label for admin UI
            $table->timestamps();
        });

        // ── Seed default values ───────────────────────────────────────────────
        $now = now();
        DB::table('cms_settings')->insert([
            // Hero
            ['key' => 'hero_title',    'value' => 'Comfort Crafted Around You',              'type' => 'text',  'group' => 'hero',     'label' => 'Hero Title',           'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hero_subtitle', 'value' => 'Fast Booking • Clear Billing • Comfortable Rooms', 'type' => 'text', 'group' => 'hero', 'label' => 'Hero Subtitle',  'created_at' => $now, 'updated_at' => $now],
            ['key' => 'hero_image',    'value' => 'https://i.pinimg.com/1200x/87/03/42/87034203c6d682ac34ca22c8b42f8d20.jpg', 'type' => 'image', 'group' => 'hero', 'label' => 'Hero Background Image', 'created_at' => $now, 'updated_at' => $now],

            // Intro section
            ['key' => 'intro_title',       'value' => 'A Good Place to Stay for Your Business Trips', 'type' => 'text', 'group' => 'intro', 'label' => 'Intro Title',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'intro_description', 'value' => 'Experience comfort and convenience in the heart of the city. Our modern rooms and exceptional service make us the perfect choice for both business travelers and leisure guests seeking a memorable stay in Metro Manila.', 'type' => 'text', 'group' => 'intro', 'label' => 'Intro Description', 'created_at' => $now, 'updated_at' => $now],

            // Policies
            ['key' => 'policy_checkin',       'value' => 'After 3:00 PM',                                              'type' => 'text', 'group' => 'policies', 'label' => 'Check-In Time',           'created_at' => $now, 'updated_at' => $now],
            ['key' => 'policy_checkout',      'value' => 'Before 12:00 PM',                                            'type' => 'text', 'group' => 'policies', 'label' => 'Check-Out Time',          'created_at' => $now, 'updated_at' => $now],
            ['key' => 'policy_downpayment',   'value' => 'Down payment is required to confirm the reservation.',       'type' => 'text', 'group' => 'policies', 'label' => 'Down Payment Policy',    'created_at' => $now, 'updated_at' => $now],
            ['key' => 'policy_cancellation',  'value' => 'If cancellation is requested within 24 hours of check-in time, it is non-refundable.', 'type' => 'text', 'group' => 'policies', 'label' => 'Cancellation Policy', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'policy_requests',      'value' => 'Guests may submit only one cancellation request and one rebooking request.',      'type' => 'text', 'group' => 'policies', 'label' => 'Request Limit Policy', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'policy_rebooking',     'value' => 'Rebooking requests may have rate differences and require staff approval.',        'type' => 'text', 'group' => 'policies', 'label' => 'Rebooking Policy',    'created_at' => $now, 'updated_at' => $now],
            ['key' => 'policy_id',            'value' => 'Please present a valid government-issued ID during check-in.',                   'type' => 'text', 'group' => 'policies', 'label' => 'ID Requirement',       'created_at' => $now, 'updated_at' => $now],

            // Room type labels & descriptions
            ['key' => 'room_executive_suite_label',       'value' => 'Executive Suite',   'type' => 'text', 'group' => 'room_types', 'label' => 'Executive Suite — Label',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_executive_suite_tagline',     'value' => 'Luxury and space combined',        'type' => 'text', 'group' => 'room_types', 'label' => 'Executive Suite — Tagline',     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_executive_suite_description', 'value' => 'Indulge in our Executive Suite, offering separate living and sleeping areas with panoramic city views. Perfect for extended stays or those seeking premium comfort with exclusive amenities.', 'type' => 'text', 'group' => 'room_types', 'label' => 'Executive Suite — Description', 'created_at' => $now, 'updated_at' => $now],

            ['key' => 'room_family_label',       'value' => 'Family Room',   'type' => 'text', 'group' => 'room_types', 'label' => 'Family Room — Label',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_family_tagline',     'value' => 'Perfect for the whole family',     'type' => 'text', 'group' => 'room_types', 'label' => 'Family Room — Tagline',     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_family_description', 'value' => 'Our spacious Family Room accommodates up to 4 guests comfortably with multiple sleeping arrangements. Designed with families in mind, featuring kid-friendly amenities and plenty of space.', 'type' => 'text', 'group' => 'room_types', 'label' => 'Family Room — Description', 'created_at' => $now, 'updated_at' => $now],

            ['key' => 'room_deluxe_label',       'value' => 'Deluxe',        'type' => 'text', 'group' => 'room_types', 'label' => 'Deluxe — Label',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_deluxe_tagline',     'value' => 'A blend of elegance and comfort',  'type' => 'text', 'group' => 'room_types', 'label' => 'Deluxe — Tagline',     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_deluxe_description', 'value' => 'Experience comfort and sophistication in our Deluxe Room, designed with modern amenities and elegant furnishings. Perfect for discerning travelers.', 'type' => 'text', 'group' => 'room_types', 'label' => 'Deluxe — Description', 'created_at' => $now, 'updated_at' => $now],

            ['key' => 'room_superior_twin_label',       'value' => 'Superior Twin', 'type' => 'text', 'group' => 'room_types', 'label' => 'Superior Twin — Label',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_superior_twin_tagline',     'value' => 'Modern comfort for two',           'type' => 'text', 'group' => 'room_types', 'label' => 'Superior Twin — Tagline',     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_superior_twin_description', 'value' => 'Our Superior Twin room features two comfortable single beds, ideal for friends or colleagues traveling together. Enjoy in...', 'type' => 'text', 'group' => 'room_types', 'label' => 'Superior Twin — Description', 'created_at' => $now, 'updated_at' => $now],

            ['key' => 'room_superior_queen_label',       'value' => 'Superior Queen', 'type' => 'text', 'group' => 'room_types', 'label' => 'Superior Queen — Label',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_superior_queen_tagline',     'value' => 'Classic comfort with a queen touch',  'type' => 'text', 'group' => 'room_types', 'label' => 'Superior Queen — Tagline',     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_superior_queen_description', 'value' => 'Our Superior Queen room offers a plush queen bed and refined interiors for guests seeking a comfortable and stylish stay.', 'type' => 'text', 'group' => 'room_types', 'label' => 'Superior Queen — Description', 'created_at' => $now, 'updated_at' => $now],

            ['key' => 'room_premier_label',       'value' => 'Premier',       'type' => 'text', 'group' => 'room_types', 'label' => 'Premier — Label',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_premier_tagline',     'value' => 'Elevated living at its finest',   'type' => 'text', 'group' => 'room_types', 'label' => 'Premier — Tagline',     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'room_premier_description', 'value' => 'Our Premier Room combines sophisticated design with premium comforts for a truly elevated stay. Featuring upscale furn...', 'type' => 'text', 'group' => 'room_types', 'label' => 'Premier — Description', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_settings');
    }
};
