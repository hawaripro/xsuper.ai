<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = str_replace('\\', '/', storage_path('framework/testing/dashboard-e2e.sqlite'));
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || str_replace('\\', '/', config('database.connections.sqlite.database')) !== $expected) {
    throw new RuntimeException('Database assertions require the isolated E2E database.');
}
$tables = [
    'users', 'content_blocks', 'audit_events', 'analytics_events', 'duration_orders', 'payment_checkouts',
    'support_tickets', 'support_messages', 'notifications', 'feedback', 'wallets', 'wallet_transactions',
    'ai_model_profiles', 'usage_rates', 'user_devices', 'api_keys', 'chat_history', 'image_jobs', 'video_jobs',
    'ai_provider_profiles', 'user_storage_upgrades',
    'user_tokens', 'token_transactions', 'token_reservations', 'token_packages', 'deposit_orders', 'prompt_templates',
];
$table = $argv[1] ?? '';
if (! in_array($table, $tables, true)) {
    throw new InvalidArgumentException('Unsupported E2E assertion table.');
}
$filters = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$query = DB::table($table);
foreach ($filters as $column => $value) {
    if (! preg_match('/^[a-z_]+$/', $column)) {
        throw new InvalidArgumentException('Invalid column.');
    }
    $query->where($column, $value);
}
echo json_encode($query->limit(100)->get(), JSON_THROW_ON_ERROR);
