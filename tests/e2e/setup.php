<?php

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AnalyticsEvent;
use App\Models\AuditEvent;
use App\Models\ContentBlock;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\UserToken;
use App\Models\Wallet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = storage_path('framework/testing/dashboard-e2e.sqlite');
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $database)) {
    throw new RuntimeException('E2E setup refuses to modify a non-isolated database.');
}
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0777, true);
}
if (! file_exists($database)) {
    touch($database);
}
Artisan::call('migrate:fresh', ['--force' => true]);
$permissions = array_fill_keys(array_keys(User::DEFAULT_PERMISSIONS), true);
foreach (['admin', 'member', 'other'] as $role) {
    $user = User::create([
        'name' => 'QA '.ucfirst($role),
        'email' => $role.'@dashboard-e2e.test',
        'password' => Hash::make('E2e-Dashboard-Only!'),
        'role' => $role === 'admin' ? 'admin' : 'member',
        'is_active' => true,
        'expires_at' => now()->addDays(20),
        'permissions' => $permissions,
        'onboarding_mode' => 'coding',
    ]);
    UserToken::create(['user_id' => $user->id, 'balance' => 500]);
    Wallet::create(['user_id' => $user->id, 'balance_microusd' => 5000000]);
}
$admin = User::where('email', 'admin@dashboard-e2e.test')->firstOrFail();
$member = User::where('email', 'member@dashboard-e2e.test')->firstOrFail();
ContentBlock::create([
    'key' => 'system.announcement',
    'locale' => 'en',
    'draft' => ['message' => 'QA announcement draft', 'level' => 'info', 'surfaces' => ['dashboard', 'pricing']],
    'is_published' => false,
    'updated_by' => $admin->id,
]);
AuditEvent::create([
    'actor_id' => $admin->id,
    'action' => 'qa.account.created',
    'subject_type' => User::class,
    'subject_id' => $member->id,
    'metadata' => ['reason' => 'Isolated QA fixture'],
]);
AnalyticsEvent::create([
    'user_id' => $member->id,
    'name' => 'app.opened',
    'path' => '/dashboard',
    'properties' => ['source' => 'e2e'],
]);
foreach (['user' => 'QA saved conversation', 'assistant' => 'Saved integration answer'] as $role => $content) {
    DB::table('chat_history')->insert([
        'user_id' => $member->id,
        'conversation_id' => 'qa-member-history',
        'role' => $role,
        'content' => $content,
        'model' => 'qa-chat',
        'created_at' => now(),
    ]);
}

$provider = AiProviderProfile::create(['slug' => 'qa-local', 'name' => 'QA isolated provider', 'is_enabled' => true, 'status' => 'unavailable']);
foreach (['chat', 'image'] as $category) {
    AiModelProfile::create([
        'provider_id' => $provider->id, 'model_id' => 'qa-'.$category, 'display_name' => 'QA '.$category.' model',
        'provider_name' => $provider->name, 'category' => $category,
        'token_cost' => $category === 'image' ? 15 : null,
        'is_enabled' => true, 'is_available' => true, 'capabilities' => [$category],
        'input_modalities' => ['text'], 'output_modalities' => [$category === 'image' ? 'image' : 'text'],
    ]);
}
UsageRate::create([
    'service' => 'image', 'meter' => 'unit', 'model' => 'qa-image', 'label' => 'QA image rate',
    'unit' => 'image', 'price_idr' => 4000, 'price_usd' => 0.25, 'is_active' => true,
]);
DB::table('prompt_templates')->insert([
    'title' => 'QA persisted coding template', 'category' => 'Coding', 'prompt_text' => 'Review this code and explain the risks.',
    'mode' => 'coding', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
fwrite(STDOUT, "Isolated dashboard E2E database seeded.\n");
