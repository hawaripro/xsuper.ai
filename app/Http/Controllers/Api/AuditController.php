<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'max:96'],
            'actor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'subject_type' => ['sometimes', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditEvent::query()
            ->with('actor:id,name,email')
            ->latest('id');

        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (isset($validated['actor_id'])) {
            $query->where('actor_id', $validated['actor_id']);
        }

        if (isset($validated['subject_type'])) {
            $subjectType = Relation::getMorphedModel($validated['subject_type']) ?? $validated['subject_type'];
            $query->where('subject_type', $subjectType);
        }

        if (isset($validated['subject_id'])) {
            $query->where('subject_id', $validated['subject_id']);
        }

        if (isset($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['from']));
        }

        if (isset($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['to']));
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 20)));
    }
}
