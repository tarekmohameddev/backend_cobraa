<?php

declare(strict_types=1);

return [
    'enabled' => env('STOX_ENABLED', false),
    'default_base_url' => env('STOX_BASE_URL', 'https://merchants.stox-eg.com/api'),
    'queue_name' => env('STOX_QUEUE', 'default'),
    'max_retry_attempts' => env('STOX_MAX_RETRY_ATTEMPTS', 3),
    'operation_log_retention_days' => env('STOX_LOG_RETENTION_DAYS', 30),
];

