<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\AuditEvent;
use App\Models\MediaCapabilityRevision;
use App\Models\UsageRate;
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
            'provider_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditEvent::query()
            ->with('actor:id,name,email')
            ->latest('id');

        if (isset($validated['provider_id'])) {
            $providerId = $validated['provider_id'];
            $models = AiModelProfile::where('provider_id', $providerId)->select('id');
            $query->where(function ($query) use ($providerId, $models): void {
                $query->where(fn ($q) => $q->where('subject_type', (new AiProviderProfile)->getMorphClass())->where('subject_id', $providerId))
                    ->orWhere(fn ($q) => $q->where('subject_type', (new AiModelProfile)->getMorphClass())->whereIn('subject_id', clone $models))
                    ->orWhere(fn ($q) => $q->where('subject_type', (new MediaCapabilityRevision)->getMorphClass())
                        ->whereIn('subject_id', MediaCapabilityRevision::whereIn('ai_model_profile_id', clone $models)->select('id')))
                    ->orWhere(fn ($q) => $q->where('subject_type', (new UsageRate)->getMorphClass())
                        ->whereIn('subject_id', UsageRate::whereIn('model', AiModelProfile::where('provider_id', $providerId)->select('model_id'))->select('id')))
                    ->orWhere('metadata->provider_id', $providerId)
                    ->orWhere('metadata->before->provider_id', $providerId)
                    ->orWhere('metadata->after->provider_id', $providerId);
            });
        }

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
