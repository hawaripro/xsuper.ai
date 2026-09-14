<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\SupportController;
use App\Models\AuditEvent;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EngagementOperationsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();

        $router = app('router');
        $routes = $router->getRoutes();
        $router->setRoutes(new \Illuminate\Routing\RouteCollection);

        Route::middleware(['web', 'auth'])->prefix('api')->group(function (): void {
            Route::get('/feedback', [FeedbackController::class, 'index']);
            Route::post('/feedback', [FeedbackController::class, 'store']);
            Route::get('/support/tickets', [SupportController::class, 'index']);
            Route::post('/support/tickets', [SupportController::class, 'store']);
            Route::get('/support/tickets/{ticket}', [SupportController::class, 'show']);
            Route::post('/support/tickets/{ticket}/replies', [SupportController::class, 'reply']);

            Route::middleware('admin')->prefix('admin')->group(function (): void {
                Route::get('/feedback', [FeedbackController::class, 'adminIndex']);
                Route::patch('/feedback/{feedback}', [FeedbackController::class, 'moderate']);
                Route::get('/support/tickets', [SupportController::class, 'adminIndex']);
                Route::get('/support/tickets/{ticket}', [SupportController::class, 'show']);
                Route::patch('/support/tickets/{ticket}', [SupportController::class, 'update']);
                Route::post('/support/tickets/{ticket}/replies', [SupportController::class, 'reply']);
                Route::post('/engagement/broadcasts', [EngagementController::class, 'broadcast']);
            });
        });

        foreach ($routes as $route) {
            $router->getRoutes()->add($route);
        }
    }

    public function test_feedback_submission_is_validated_audited_and_history_is_paginated_and_private(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        Feedback::create([
            'user_id' => $other->id,
            'rating' => 5,
            'category' => 'product',
            'message' => 'This belongs to somebody else.',
        ]);

        $this->actingAs($member)->postJson('/api/feedback', [
            'rating' => 0,
            'category' => '',
            'message' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['rating', 'category', 'message']);

        $created = $this->actingAs($member)->postJson('/api/feedback', [
            'rating' => 4,
            'category' => 'product',
            'message' => 'The dashboard is clear and useful.',
        ])->assertCreated()->assertJsonPath('data.status', 'new');

        $feedbackId = $created->json('data.id');
        $this->assertDatabaseHas('feedback', [
            'id' => $feedbackId,
            'user_id' => $member->id,
            'rating' => 4,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $member->id,
            'action' => 'feedback.created',
            'subject_type' => Feedback::class,
            'subject_id' => $feedbackId,
        ]);

        $this->actingAs($member)->getJson('/api/feedback?per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.id', $feedbackId);
    }

    public function test_ticket_creation_thread_and_member_reply_are_audited_and_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $created = $this->actingAs($owner)->postJson('/api/support/tickets', [
            'subject' => 'Billing balance mismatch',
            'category' => 'billing',
            'priority' => 'high',
            'body' => 'My latest top-up is not reflected in the wallet.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.messages.0.is_staff', false);

        $ticketId = $created->json('data.id');
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $owner->id,
            'action' => 'support.ticket.created',
            'subject_type' => SupportTicket::class,
            'subject_id' => $ticketId,
        ]);

        $this->actingAs($stranger)->getJson("/api/support/tickets/{$ticketId}")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/support/tickets/{$ticketId}/replies", [
            'body' => 'I should not be able to write here.',
        ])->assertNotFound();

        $reply = $this->actingAs($owner)->postJson("/api/support/tickets/{$ticketId}/replies", [
            'body' => 'I can provide the payment receipt if needed.',
        ])->assertCreated()->assertJsonPath('data.is_staff', false);

        $this->assertDatabaseHas('support_messages', [
            'id' => $reply->json('data.id'),
            'ticket_id' => $ticketId,
            'user_id' => $owner->id,
            'is_staff' => false,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $owner->id,
            'action' => 'support.ticket.replied',
            'subject_type' => SupportTicket::class,
            'subject_id' => $ticketId,
        ]);

        $this->actingAs($owner)->getJson('/api/support/tickets?per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.id', $ticketId);
        $this->actingAs($owner)->getJson("/api/support/tickets/{$ticketId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_closed_ticket_rejects_member_reply_with_validation_semantics(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Closed request',
            'category' => 'account',
            'priority' => 'normal',
            'status' => 'closed',
        ]);

        $this->actingAs($owner)->postJson("/api/support/tickets/{$ticket->id}/replies", [
            'body' => 'Trying to reopen a closed ticket.',
        ])->assertUnprocessable()->assertJsonValidationErrors('ticket');

        $this->assertDatabaseCount('support_messages', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_admin_feedback_moderation_is_authorized_paginated_and_audited(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $feedback = Feedback::create([
            'user_id' => $member->id,
            'rating' => 5,
            'category' => 'product',
            'message' => 'This should be considered for a testimonial.',
        ]);

        $this->actingAs($member)->patchJson("/api/admin/feedback/{$feedback->id}", [
            'status' => 'reviewed',
        ])->assertForbidden();

        $this->actingAs($admin)->patchJson("/api/admin/feedback/{$feedback->id}", [
            'status' => 'reviewed',
            'is_testimonial' => true,
            'admin_note' => 'Approved for homepage use.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'reviewed')
            ->assertJsonPath('data.is_testimonial', true);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'feedback.moderated',
            'subject_type' => Feedback::class,
            'subject_id' => $feedback->id,
        ]);
        $this->actingAs($admin)->getJson('/api/admin/feedback?status=reviewed&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.user.id', $member->id);
    }

    public function test_admin_can_assign_and_transition_ticket_with_audit_trail(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Account assistance',
            'category' => 'account',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/support/tickets/{$ticket->id}", [
            'assigned_to' => $owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');

        $this->actingAs($admin)->patchJson("/api/admin/support/tickets/{$ticket->id}", [
            'assigned_to' => $assignee->id,
            'status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.assigned_to', $assignee->id)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'support.ticket.updated',
            'subject_type' => SupportTicket::class,
            'subject_id' => $ticket->id,
        ]);
        $this->actingAs($admin)->getJson('/api/admin/support/tickets?status=in_progress&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.user.id', $owner->id)
            ->assertJsonPath('data.0.assignee.id', $assignee->id);
    }

    public function test_admin_reply_notifies_owner_and_transitions_ticket_transactionally(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'subject' => 'Technical issue',
            'category' => 'technical',
            'priority' => 'normal',
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/support/tickets/{$ticket->id}/replies", [
            'body' => 'Please retry after clearing the cached session.',
        ])->assertCreated()
            ->assertJsonPath('data.is_staff', true)
            ->assertJsonPath('ticket.status', 'waiting_on_member');

        $messageId = $response->json('data.id');
        $this->assertDatabaseHas('support_messages', [
            'id' => $messageId,
            'ticket_id' => $ticket->id,
            'user_id' => $admin->id,
            'is_staff' => true,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'kind' => 'support',
        ]);
        $notification = Notification::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame($ticket->id, $notification->metadata['ticket_id']);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'support.ticket.replied',
            'subject_type' => SupportTicket::class,
            'subject_id' => $ticket->id,
        ]);
    }

    public function test_broadcast_segments_create_exact_durable_notifications_and_audits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unlimited = User::factory()->create(['expires_at' => null]);
        $active = User::factory()->create(['expires_at' => now()->addDay()]);
        $expired = User::factory()->create(['expires_at' => now()->subDay()]);
        $memberIds = [$unlimited->id, $active->id, $expired->id];

        $expectations = [
            'all' => $memberIds,
            'active' => [$unlimited->id, $active->id],
            'expired' => [$expired->id],
        ];

        foreach ($expectations as $segment => $expectedIds) {
            $title = "{$segment} recipients";
            $response = $this->actingAs($admin)->postJson('/api/admin/engagement/broadcasts', [
                'segment' => $segment,
                'title' => $title,
                'body' => "A durable message for the {$segment} segment.",
                'action_url' => '/dashboard',
            ])->assertCreated()
                ->assertJsonPath('data.segment', $segment)
                ->assertJsonPath('data.recipient_count', count($expectedIds));

            $broadcastId = $response->json('data.id');
            $notifications = Notification::where('title', $title)->orderBy('user_id')->get();
            $this->assertSame($expectedIds, $notifications->pluck('user_id')->all());
            $this->assertTrue($notifications->every(
                fn (Notification $notification): bool => $notification->metadata['broadcast_id'] === $broadcastId
            ));
            $this->assertDatabaseHas('audit_events', [
                'actor_id' => $admin->id,
                'action' => 'engagement.broadcast.created',
            ]);
        }

        $this->assertDatabaseCount('notifications', 6);
        $this->assertDatabaseCount('audit_events', 3);
        $this->assertSame(3, AuditEvent::where('action', 'engagement.broadcast.created')->count());
    }

    public function test_broadcast_requires_admin_and_valid_segment(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'segment' => 'everyone-ish',
            'title' => 'Notice',
            'body' => 'A message that should not be delivered.',
        ];

        $this->actingAs($member)->postJson('/api/admin/engagement/broadcasts', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/admin/engagement/broadcasts', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('segment');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
