<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SupportTicket::query()
            ->where('user_id', $user->id)
            ->with('assignee:id,name')
            ->withCount('messages');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json(
            $query->latest('updated_at')->latest('id')
                ->paginate($validated['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->adminUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'integer', 'exists:users,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SupportTicket::query()
            ->with(['user:id,name,email', 'assignee:id,name,email'])
            ->withCount('messages');

        foreach (['status', 'priority', 'assigned_to'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        return response()->json(
            $query->latest('updated_at')->latest('id')
                ->paginate($validated['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'category' => ['required', 'string', 'max:40'],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'body' => ['required', 'string', 'min:2', 'max:10000'],
        ]);

        $ticket = DB::transaction(function () use ($user, $validated): SupportTicket {
            $ticket = SupportTicket::create([
                'user_id' => $user->id,
                'subject' => $validated['subject'],
                'category' => $validated['category'],
                'priority' => $validated['priority'] ?? 'normal',
                'status' => 'open',
                'last_replied_at' => now(),
            ]);
            $message = $ticket->messages()->create([
                'user_id' => $user->id,
                'body' => $validated['body'],
                'is_staff' => false,
            ]);

            $this->audit->record($user, 'support.ticket.created', $ticket, [
                'category' => $ticket->category,
                'priority' => $ticket->priority,
                'initial_message_id' => $message->id,
            ]);

            return $ticket;
        });

        return response()->json([
            'data' => $this->loadThread($ticket),
        ], 201);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $record = $this->visibleTickets($user)->findOrFail($ticket);

        return response()->json(['data' => $this->loadThread($record)]);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
        ]);

        [$message, $record] = DB::transaction(function () use ($user, $ticket, $validated): array {
            $record = $this->visibleTickets($user)->lockForUpdate()->findOrFail($ticket);
            $isStaff = $user->isAdmin();

            if (! $isStaff && $record->status === 'closed') {
                throw ValidationException::withMessages([
                    'ticket' => ['Closed tickets cannot receive new replies.'],
                ]);
            }

            $previousStatus = $record->status;
            $message = $record->messages()->create([
                'user_id' => $user->id,
                'body' => $validated['body'],
                'is_staff' => $isStaff,
            ]);
            $record->update([
                'status' => $isStaff ? 'waiting_on_member' : 'open',
                'last_replied_at' => now(),
            ]);

            if ($isStaff) {
                Notification::create([
                    'user_id' => $record->user_id,
                    'kind' => 'support',
                    'title' => 'New support reply',
                    'body' => "Your ticket “{$record->subject}” has a new reply.",
                    'action_url' => "/bantuan?ticket={$record->id}",
                    'metadata' => [
                        'ticket_id' => $record->id,
                        'message_id' => $message->id,
                    ],
                ]);
            }

            $this->audit->record($user, 'support.ticket.replied', $record, [
                'message_id' => $message->id,
                'is_staff' => $isStaff,
                'from_status' => $previousStatus,
                'to_status' => $record->status,
            ]);

            return [$message, $record];
        });

        return response()->json([
            'data' => $message->load('user:id,name,role'),
            'ticket' => $record->only(['id', 'status', 'last_replied_at']),
        ], 201);
    }

    public function update(Request $request, int $ticket): JsonResponse
    {
        $admin = $this->adminUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'assigned_to' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query->where('role', 'admin')),
            ],
        ]);

        if (! $request->hasAny(['status', 'assigned_to'])) {
            throw ValidationException::withMessages([
                'ticket' => ['A status or assignment is required.'],
            ]);
        }

        $record = DB::transaction(function () use ($admin, $ticket, $validated): SupportTicket {
            $record = SupportTicket::query()->lockForUpdate()->findOrFail($ticket);
            $before = $record->only(['status', 'assigned_to']);
            $record->update($validated);

            $this->audit->record($admin, 'support.ticket.updated', $record, [
                'before' => $before,
                'after' => $record->only(['status', 'assigned_to']),
            ]);

            return $record;
        });

        return response()->json([
            'data' => $record->load(['user:id,name,email', 'assignee:id,name,email']),
        ]);
    }

    private function visibleTickets(User $user): Builder
    {
        $query = SupportTicket::query();

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    private function loadThread(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'user:id,name,email',
            'assignee:id,name,email',
            'messages.user:id,name,role',
        ]);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }

    private function adminUser(Request $request): User
    {
        $user = $this->authenticatedUser($request);
        abort_unless($user->isAdmin(), 403, 'Forbidden.');

        return $user;
    }
}
