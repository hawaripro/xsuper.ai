<?php

$applicationHost = parse_url((string) env('APP_URL', 'http://127.0.0.1:8000'), PHP_URL_HOST) ?: '127.0.0.1';
$localOrigins = env('APP_ENV', 'production') === 'local' ? ',localhost,127.0.0.1' : '';
$allowedOrigins = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', $applicationHost.$localOrigins))))));

return [
    'default' => 'reverb',
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => 10_000,
            'scaling' => ['enabled' => false, 'channel' => 'reverb'],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'allowed_origins' => $allowedOrigins,
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_connections' => (int) env('REVERB_APP_MAX_CONNECTIONS', 1000),
            'max_message_size' => 10_000,
            'accept_client_events_from' => 'none',
            'rate_limiting' => [
                'enabled' => true,
                'max_attempts' => 60,
                'decay_seconds' => 60,
                'terminate_on_limit' => false,
            ],
        ]],
    ],
];
