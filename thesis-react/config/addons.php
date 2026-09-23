<?php

return [
    'catalog' => [
        'rollaway_bed' => [
            'id' => 'rollaway_bed',
            'name' => 'Rollaway Bed',
            'price' => 800.00,
            'max_quantity' => 2,
        ],
        'extra_pillows_and_blankets' => [
            'id' => 'extra_pillows_and_blankets',
            'name' => 'Extra Pillows and Blankets',
            'price' => 350.00,
            'max_quantity' => 6,
        ],
        'breakfast_package' => [
            'id' => 'breakfast_package',
            'name' => 'Breakfast Package',
            'price' => 650.00,
            'max_quantity' => 12,
        ],
        'early_check_in' => [
            'id' => 'early_check_in',
            'name' => 'Early Check-In',
            'price' => 500.00,
            'max_quantity' => 1,
        ],
        'late_check_out' => [
            'id' => 'late_check_out',
            'name' => 'Late Check-Out',
            'price' => 500.00,
            'max_quantity' => 1,
        ],
        'extra_toiletries_kit' => [
            'id' => 'extra_toiletries_kit',
            'name' => 'Extra Toiletries Kit',
            'price' => 250.00,
            'max_quantity' => 10,
        ],
        'laundry_service' => [
            'id' => 'laundry_service',
            'name' => 'Laundry Service',
            'price' => 400.00,
            'max_quantity' => 10,
        ],
    ],
    'max_distinct_items_per_room' => 7,
    'extra_charges' => [
        'max_lines_per_request' => 20,
        'max_amount_per_line' => 100000.00,
        'categories' => [
            'Broken Item',
            'Lost Key / Card',
            'Extra Towel / Linen',
            'Room Damage',
            'Late Check-Out Fee',
            'Mini Bar Consumption',
            'Laundry Service',
            'Room Service',
            'Parking Fee',
            'Other',
        ],
    ],
];
