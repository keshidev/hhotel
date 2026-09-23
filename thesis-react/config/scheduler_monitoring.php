<?php

return [
    'heartbeat_name' => env('SCHEDULER_HEARTBEAT_NAME', 'default'),
    'max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS', 180),
];
