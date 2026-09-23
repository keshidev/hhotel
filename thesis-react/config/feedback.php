<?php

return [
    'token_lifetime_days' => max(1, (int) env('FEEDBACK_TOKEN_LIFETIME_DAYS', 30)),
    'view_attempts_per_minute' => max(5, (int) env('FEEDBACK_VIEW_ATTEMPTS_PER_MINUTE', 30)),
    'view_ip_attempts_per_minute' => max(10, (int) env('FEEDBACK_VIEW_IP_ATTEMPTS_PER_MINUTE', 120)),
    'submit_attempts_per_minute' => max(2, (int) env('FEEDBACK_SUBMIT_ATTEMPTS_PER_MINUTE', 5)),
    'submit_ip_attempts_per_minute' => max(5, (int) env('FEEDBACK_SUBMIT_IP_ATTEMPTS_PER_MINUTE', 30)),
    'public_reads_per_minute' => max(10, (int) env('FEEDBACK_PUBLIC_READS_PER_MINUTE', 120)),
];
