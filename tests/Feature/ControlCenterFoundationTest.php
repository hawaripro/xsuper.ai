<?php

namespace Tests\Feature;

use App\Models\ContentBlock;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlCenterFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_center_records_keep_relations_and_structured_data(): void
    {
        $actor = User::factory()->create();
        $recipient = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $recipient->id,
            'kind' => 'account',
            'title' => 'Akses disetujui',
            'body' => 'Perangkat baru telah disetujui.',
            'action_url' => '/profile',
            'metadata' => ['device' => 'Chrome'],
        ]);
        $referral = Referral::create([
            'referrer_id' => $actor->id,
            'referred_id' => $recipient->id,
            'code' => 'UTR-FOUNDATION',
            'status' => 'attributed',
            'attributed_at' => now(),
        ]);
        ContentBlock::create([
            'key' => 'home.hero',
            'locale' => 'id',
            'draft' => ['headline' => 'Draft'],
            'published' => ['headline' => 'Published'],
            'is_published' => true,
            'updated_by' => $actor->id,
        ]);
        $audit = app(AuditService::class)->record($actor, 'referral.attributed', $referral, [
            'ip' => '127.0.0.1',
        ]);

        $this->assertSame('Chrome', $notification->fresh()->metadata['device']);
        $this->assertSame($recipient->id, $referral->fresh()->referred->id);
        $this->assertSame('Published', ContentBlock::first()->published['headline']);
        $this->assertSame('referral.attributed', $audit->action);
        $this->assertSame($actor->id, $audit->actor->id);
        $this->assertSame(Referral::class, $audit->subject_type);
    }

    public function test_migration_removes_retired_chat_pro_permission_from_existing_users(): void
    {
        $user = User::factory()->create([
            'permissions' => [...User::DEFAULT_PERMISSIONS, 'chat_ai_pro' => true],
        ]);

        $migration = require database_path('migrations/2026_09_14_064035_create_control_center_tables.php');
        $migration->up();

        $this->assertArrayNotHasKey('chat_ai_pro', $user->fresh()->permissions);
    }
}
