<?php

namespace Database\Seeders;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AnalyticsEvent;
use App\Models\AuditEvent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ContentBlock;
use App\Models\DurationOrder;
use App\Models\Feedback;
use App\Models\ImageJob;
use App\Models\Notification;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\UsageLog;
use App\Models\UsageRate;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\VideoJob;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OperationalDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'ops.admin@xsuper.test'],
            [
                'name' => 'Ops Admin',
                'password' => Hash::make('Ops-Local-2026!'),
                'role' => 'admin',
                'is_active' => true,
            ],
        );
        $member = User::firstOrCreate(
            ['email' => 'ops.member@xsuper.test'],
            [
                'name' => 'Ops Member',
                'password' => Hash::make('Ops-Local-2026!'),
                'role' => 'member',
                'is_active' => true,
                'expires_at' => now()->addDays(45),
                'permissions' => User::DEFAULT_PERMISSIONS,
            ],
        );
        $memberTwo = User::firstOrCreate(
            ['email' => 'ops.creator@xsuper.test'],
            [
                'name' => 'Ops Creator',
                'password' => Hash::make('Ops-Local-2026!'),
                'role' => 'member',
                'is_active' => true,
                'expires_at' => now()->addDays(6),
                'permissions' => User::DEFAULT_PERMISSIONS,
            ],
        );

        foreach ([$admin, $member, $memberTwo] as $user) {
            UserDevice::updateOrCreate(
                ['device_hash' => hash('sha256', 'ops-'.$user->id)],
                [
                    'user_id' => $user->id,
                    'device_name' => $user->isAdmin() ? 'Admin Chrome' : 'Member Chrome',
                    'device_type' => 'browser',
                    'user_agent' => 'Operational seed browser',
                    'ip_address' => '127.0.0.1',
                    'status' => 'active',
                    'last_active_at' => now(),
                ],
            );
        }

        $provider = AiProviderProfile::updateOrCreate(
            ['slug' => 'ai-proxy'],
            [
                'name' => 'XSuper.ai Model Network',
                'status' => 'healthy',
                'is_enabled' => true,
                'capabilities' => ['chat', 'image', 'video'],
                'last_checked_at' => now()->subMinutes(4),
                'last_error' => null,
            ],
        );
        $catalogModels = [
            [
                'model_id' => 'xsuper-chat-premium',
                'display_name' => 'XSuper Chat Premium',
                'provider_name' => 'XSuper.ai',
                'category' => 'chat',
                'tier' => 'Authentic',
                'description_id' => 'Model percakapan andalan untuk kerja harian: menulis, analisis, dan coding dengan konteks panjang.',
                'description_en' => 'Flagship chat model for daily work: writing, analysis, and coding with long context.',
                'logo_url' => '/xsuper-icon-v2.png',
                'context_window' => 256000,
                'max_output_tokens' => 8192,
                'capabilities' => ['chat', 'vision', 'tools'],
                'input_modalities' => ['text', 'image'],
                'output_modalities' => ['text'],
                'badges' => ['Popular'],
                'sort_order' => 10,
                'rates' => ['input_tokens' => 2.00, 'output_tokens' => 8.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
            ],
            [
                'model_id' => 'xsuper-chat-fast',
                'display_name' => 'XSuper Chat Fast',
                'provider_name' => 'XSuper.ai',
                'category' => 'chat',
                'tier' => 'Canva',
                'description_id' => 'Model ringan dan cepat untuk obrolan, ide, dan tugas sederhana dengan biaya rendah.',
                'description_en' => 'Light, fast model for chat, ideas, and simple tasks at low cost.',
                'logo_url' => '/xsuper-icon-v2.png',
                'context_window' => 128000,
                'max_output_tokens' => 4096,
                'capabilities' => ['chat'],
                'input_modalities' => ['text'],
                'output_modalities' => ['text'],
                'badges' => ['Hemat'],
                'sort_order' => 20,
                'rates' => ['input_tokens' => 0.15, 'output_tokens' => 0.60, 'cache_read' => 0.02, 'cache_write' => 0.19],
            ],
            [
                'model_id' => 'xsuper-reasoning-pro',
                'display_name' => 'XSuper Reasoning Pro',
                'provider_name' => 'XSuper.ai',
                'category' => 'chat',
                'tier' => 'Authentic',
                'description_id' => 'Model penalaran untuk masalah kompleks: matematika, arsitektur sistem, dan debugging mendalam.',
                'description_en' => 'Reasoning model for complex problems: math, system architecture, and deep debugging.',
                'logo_url' => '/xsuper-icon-v2.png',
                'context_window' => 200000,
                'max_output_tokens' => 32768,
                'capabilities' => ['chat', 'reasoning', 'tools'],
                'input_modalities' => ['text'],
                'output_modalities' => ['text'],
                'badges' => ['Reasoning'],
                'sort_order' => 30,
                'rates' => ['input_tokens' => 4.00, 'output_tokens' => 16.00, 'cache_read' => 0.40, 'cache_write' => 5.00],
            ],
            [
                'model_id' => 'xsuper-image-studio',
                'display_name' => 'XSuper Image Studio',
                'provider_name' => 'XSuper.ai',
                'category' => 'image',
                'tier' => 'Canva',
                'description_id' => 'Generator gambar untuk visual produk, ilustrasi, dan materi pemasaran.',
                'description_en' => 'Image generator for product visuals, illustrations, and marketing assets.',
                'logo_url' => '/xsuper-icon-v2.png',
                'context_window' => null,
                'max_output_tokens' => null,
                'capabilities' => ['text-to-image'],
                'input_modalities' => ['text'],
                'output_modalities' => ['image'],
                'badges' => ['Image'],
                'sort_order' => 40,
                'rates' => [],
            ],
        ];

        foreach ($catalogModels as $definition) {
            $rates = $definition['rates'];
            unset($definition['rates']);
            $profile = AiModelProfile::updateOrCreate(
                ['model_id' => $definition['model_id']],
                $definition + [
                    'provider_id' => $provider->id,
                    'is_enabled' => true,
                    'is_available' => true,
                    'last_seen_at' => now()->subMinutes(4),
                ],
            );
            foreach ($rates as $meter => $price) {
                UsageRate::updateOrCreate(
                    ['service' => 'api', 'meter' => $meter, 'model' => $profile->model_id],
                    [
                        'label' => $profile->display_name.' '.str_replace('_', ' ', $meter),
                        'unit' => '1M tokens',
                        'price_usd' => $price,
                        'price_idr' => round($price * 16000, 6),
                        'is_active' => true,
                        'sort_order' => $profile->sort_order * 10,
                    ],
                );
            }
        }
        $chatModel = AiModelProfile::where('model_id', 'xsuper-chat-premium')->firstOrFail();
        $imageModel = AiModelProfile::where('model_id', 'xsuper-image-studio')->firstOrFail();

        Wallet::updateOrCreate(['user_id' => $member->id], ['balance_microusd' => 25_000_000]);
        Wallet::updateOrCreate(['user_id' => $memberTwo->id], ['balance_microusd' => 4_250_000]);

        DurationOrder::firstOrCreate(
            ['user_id' => $member->id, 'package' => '1_month', 'status' => 'approved'],
            [
                'days' => 30,
                'price' => 55_000,
                'approved_at' => now()->subDays(3),
                'approved_by' => $admin->id,
                'note' => 'Operational starter subscription',
            ],
        );
        DurationOrder::firstOrCreate(
            ['user_id' => $memberTwo->id, 'package' => '1_week', 'status' => 'pending'],
            ['days' => 7, 'price' => 20_000, 'note' => 'Awaiting payment confirmation'],
        );

        foreach ([$member, $memberTwo] as $index => $user) {
            UsageLog::firstOrCreate(
                ['user_id' => $user->id, 'model' => $chatModel->model_id, 'created_at' => now()->subDays(2 - $index)],
                [
                    'source' => 'web',
                    'prompt_tokens' => 1_240,
                    'completion_tokens' => 860,
                    'total_tokens' => 2_100,
                    'credit' => 2.1,
                    'cost_microusd' => 2_100_000,
                ],
            );
        }

        $conversation = ChatConversation::firstOrCreate(
            ['user_id' => $member->id, 'title' => 'Landing copy ideas'],
            ['model' => $chatModel->model_id],
        );
        foreach ([
            ['user', 'Buat tiga angle copy landing untuk platform AI.'],
            ['assistant', 'Angle 1: workspace tunggal. Angle 2: akses model premium. Angle 3: harga lokal.'],
        ] as $index => [$role, $content]) {
            ChatMessage::firstOrCreate(
                ['conversation_id' => $conversation->id, 'role' => $role, 'content' => $content],
                ['user_id' => $member->id, 'model' => $chatModel->model_id, 'tokens_used' => $index ? 420 : 180],
            );
        }

        ImageJob::firstOrCreate(
            ['job_id' => '11111111-1111-4111-8111-111111111111'],
            [
                'user_id' => $member->id,
                'model' => $imageModel->model_id,
                'prompt' => 'Abstract red holographic orb assistant in a clean product interface',
                'size' => '1024x1024',
                'quantity' => 1,
                'status' => 'completed',
                'result_urls' => ['/xsuper-icon-v2.png'],
                'billing_reserved_microusd' => 1_000_000,
                'billing_reference_id' => 'image:seed-complete',
                'billing_status' => 'settled',
            ],
        );
        VideoJob::firstOrCreate(
            ['job_id' => 'video-seed-complete'],
            [
                'user_id' => $member->id,
                'mode' => 'prompt',
                'prompt' => 'Short cinematic product reveal for XSuper.ai',
                'model' => 'veo-3.1-fast',
                'aspect_ratio' => '16:9',
                'duration' => 8,
                'tokens_used' => 60,
                'billing_reserved_microusd' => 3_000_000,
                'billing_reference_id' => 'video:seed-complete',
                'billing_status' => 'settled',
                'settings' => ['seed' => true],
                'status' => 'completed',
                'video_url' => '/xsuper-icon-v2.png',
                'thumbnail_url' => '/xsuper-icon-v2.png',
            ],
        );

        Feedback::firstOrCreate(
            ['user_id' => $member->id, 'category' => 'general', 'message' => 'Control center feels clear and fast. I can understand my account state quickly.'],
            ['rating' => 5, 'status' => 'reviewed', 'is_testimonial' => true, 'admin_note' => 'Approved operational testimonial'],
        );
        $ticket = SupportTicket::firstOrCreate(
            ['user_id' => $member->id, 'subject' => 'API onboarding check'],
            ['category' => 'api', 'priority' => 'normal', 'status' => 'waiting_on_member', 'assigned_to' => $admin->id, 'last_replied_at' => now()->subHours(2)],
        );
        SupportMessage::firstOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $member->id, 'body' => 'Can you confirm which model ID to use from the dashboard?'],
            ['is_staff' => false],
        );
        SupportMessage::firstOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $admin->id, 'body' => 'Use the enabled model from AI Catalog; test with a small prompt first.'],
            ['is_staff' => true],
        );

        Notification::firstOrCreate(
            ['user_id' => $member->id, 'title' => 'Welcome to the new Control Center'],
            ['kind' => 'system', 'body' => 'Usage, billing, support, and account actions now live in one operational workspace.', 'action_url' => '/dashboard'],
        );
        Notification::firstOrCreate(
            ['user_id' => $member->id, 'title' => 'Support replied'],
            ['kind' => 'support', 'body' => 'Ops Admin replied to your API onboarding ticket.', 'action_url' => '/bantuan'],
        );

        ContentBlock::firstOrCreate(
            ['key' => 'system.announcement', 'locale' => 'id'],
            [
                'draft' => ['message' => 'Control Center baru tersedia dengan usage, support, dan AI catalog.', 'level' => 'info', 'action' => ['label' => 'Buka dashboard', 'url' => '/dashboard']],
                'published' => null,
                'is_published' => false,
                'updated_by' => $admin->id,
            ],
        );

        AnalyticsEvent::firstOrCreate(
            ['user_id' => $member->id, 'name' => 'app.opened', 'session_id' => 'ops-seed-session'],
            ['properties' => ['source' => 'seed'], 'path' => '/dashboard'],
        );
        AnalyticsEvent::firstOrCreate(
            ['user_id' => $member->id, 'name' => 'chat.started', 'session_id' => 'ops-seed-session'],
            ['properties' => ['model' => $chatModel->model_id], 'path' => '/chat'],
        );

        AuditEvent::firstOrCreate(
            ['actor_id' => $admin->id, 'action' => 'ops.seeded', 'metadata' => ['scope' => 'operational-demo']],
            ['ip_address' => '127.0.0.1', 'user_agent' => 'OperationalDataSeeder'],
        );
    }
}
