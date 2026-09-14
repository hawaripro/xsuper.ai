<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeedbackController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $feedback = Feedback::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json($feedback);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'category' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $feedback = DB::transaction(function () use ($user, $validated): Feedback {
            $feedback = Feedback::create([
                'user_id' => $user->id,
                ...$validated,
                'status' => Feedback::STATUS_NEW,
                'is_testimonial' => false,
            ]);

            $this->audit->record($user, 'feedback.created', $feedback, [
                'category' => $feedback->category,
                'rating' => $feedback->rating,
            ]);

            return $feedback;
        });

        return response()->json(['data' => $feedback], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->adminUser($request);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Feedback::STATUSES)],
            'is_testimonial' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Feedback::query()->with('user:id,name,email');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (array_key_exists('is_testimonial', $validated)) {
            $query->where('is_testimonial', $validated['is_testimonial']);
        }

        return response()->json(
            $query->latest('id')->paginate($validated['per_page'] ?? 20)->withQueryString()
        );
    }

    public function moderate(Request $request, int $feedback): JsonResponse
    {
        $admin = $this->adminUser($request);
        $record = Feedback::query()->findOrFail($feedback);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Feedback::STATUSES)],
            'is_testimonial' => ['sometimes', 'boolean'],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (! $request->hasAny(['status', 'is_testimonial', 'admin_note'])) {
            throw ValidationException::withMessages([
                'feedback' => ['At least one moderation field is required.'],
            ]);
        }

        $record = DB::transaction(function () use ($admin, $record, $validated): Feedback {
            $before = $record->only(['status', 'is_testimonial', 'admin_note']);
            $record->update($validated);

            $this->audit->record($admin, 'feedback.moderated', $record, [
                'before' => $before,
                'after' => $record->only(['status', 'is_testimonial', 'admin_note']),
            ]);

            return $record;
        });

        return response()->json(['data' => $record]);
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
