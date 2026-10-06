<?php

return [
    'demo_mode' => filter_var(env('VITE_DEMO_MODE', false), FILTER_VALIDATE_BOOL),
    'demo_password' => (string) env('NADI_DEMO_PASSWORD', ''),
    'allow_demo_seed' => filter_var(env('NADI_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL),
    'trusted_proxies' => env('NADI_TRUSTED_PROXIES'),
    'security' => [
        'login_max_attempts' => (int) env('NADI_LOGIN_MAX_ATTEMPTS', 5),
        'login_ip_max_attempts' => (int) env('NADI_LOGIN_IP_MAX_ATTEMPTS', 30),
        'login_decay_seconds' => (int) env('NADI_LOGIN_DECAY_SECONDS', 60),
    ],
    'monitoring' => [
        'critical_level1_minutes' => (int) env('NADI_CRITICAL_ESCALATE_LEVEL1_MINUTES', 120),
        'critical_level2_minutes' => (int) env('NADI_CRITICAL_ESCALATE_LEVEL2_MINUTES', 480),
        'notification_retention_days' => (int) env('NADI_NOTIFICATION_RETENTION_DAYS', 180),
        'sse_stream_seconds' => (int) env('NADI_SSE_STREAM_SECONDS', 20),
    ],
];
