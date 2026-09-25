<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModelProfile;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\StorageUpgradePlan;
use App\Models\UsageRate;
use App\Models\Wallet;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'duration_packages' => DurationPackagePrice::catalog(),
            'usage_rates' => UsageRate::query()->orderBy('sort_order')->orderBy('label')->get(),
            'storage_plans' => StorageUpgradePlan::query()->orderBy('sort_order')->orderBy('price_idr')->get(),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $packages = array_filter(
            DurationPackagePrice::catalog(),
            fn (array $package): bool => $package['is_active'],
        );

        return response()->json([
            'duration_packages' => $packages,
            'usage_rates' => UsageRate::publicCatalog(),
            'wallet' => [
                'balance_microusd' => Wallet::balance((int) $request->user()->id),
                'balance_usd' => Wallet::balance((int) $request->user()->id) / 1_000_000,
            ],
        ]);
    }

    public function saveDuration(Request $request, string $package, AuditService $audit): JsonResponse
    {
        abort_unless(array_key_exists($package, DurationOrder::PACKAGES), 404);
        $validated = $request->validate($this->durationRules(false));
        $prices = $this->persistDurations($request, [['package' => $package, ...$validated]], $audit);

        return response()->json(['duration_package' => $prices->firstWhere('package', $package)]);
    }

    public function saveStoragePlan(Request $request, AuditService $audit, ?StorageUpgradePlan $plan = null): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('storage_upgrade_plans', 'key')->ignore($plan?->id)],
            'label' => ['required', 'string', 'max:120'],
            'extra_gb' => ['required', 'numeric', 'min:0.1', 'max:1024'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'price_idr' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'price_usd' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $attributes = [
            'key' => $validated['key'], 'label' => $validated['label'],
            'extra_bytes' => (int) round($validated['extra_gb'] * (1024 ** 3)),
            'days' => (int) $validated['days'], 'price_idr' => (int) $validated['price_idr'],
            'price_usd' => $validated['price_usd'], 'is_active' => $validated['is_active'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ];
        $model = DB::transaction(function () use ($plan, $attributes, $request, $audit): StorageUpgradePlan {
            if ($plan?->exists) {
                $plan->update($attributes);
                $audit->record($request->user(), 'storage_plan.updated', $plan);

                return $plan->fresh();
            }
            $created = StorageUpgradePlan::create($attributes);
            $audit->record($request->user(), 'storage_plan.created', $created);

            return $created;
        });

        return response()->json(['storage_plan' => $model], $plan?->exists ? 200 : 201);
    }

    public function destroyStoragePlan(Request $request, StorageUpgradePlan $plan, AuditService $audit): JsonResponse
    {
        $audit->record($request->user(), 'storage_plan.deleted', $plan);
        $plan->delete();

        return response()->json(['deleted' => true]);
    }

    public function saveUsageRate(Request $request, AuditService $audit, ?UsageRate $usageRate = null): JsonResponse
    {
        if ($usageRate?->exists) {
            $request->merge($usageRate->only(['service', 'meter', 'model']));
        }
        $validated = $request->validate([
            'service' => ['required', Rule::in(UsageRate::SERVICES)],
            'meter' => ['required', Rule::in(UsageRate::METERS)],
            'model' => ['required', 'string', 'max:120'],
            ...$this->rateRules(false),
        ]);
        $items = [['id' => $usageRate?->id, ...$validated]];
        $rates = $this->persistRates($request, $items, $audit, $usageRate === null);
        $rate = $usageRate ? $rates->firstWhere('id', $usageRate->id) : $rates->first(fn (UsageRate $rate): bool => $rate->wasRecentlyCreated);

        return response()->json(['usage_rate' => $rate], $usageRate ? 200 : 201);
    }

    public function destroyUsageRate(Request $request, UsageRate $usageRate, AuditService $audit): JsonResponse
    {
        $this->deleteRates($request, [$usageRate->id], $audit);

        return response()->json(['message' => 'Usage rate deleted.']);
    }

    public function bulkSaveDurations(Request $request, AuditService $audit): JsonResponse
    {
        $rules = [
            'items' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'items.*' => ['required', 'array:package,price_idr,price_usd,is_active,sort_order', 'min:2'],
            'items.*.package' => ['required', 'string', 'distinct', Rule::in(array_keys(DurationOrder::PACKAGES))],
        ];
        foreach ($this->durationRules() as $field => $rule) {
            $rules['items.*.'.$field] = $rule;
        }
        $items = $request->validate($rules)['items'];
        $this->persistDurations($request, $items, $audit);

        return response()->json(['duration_packages' => DurationPackagePrice::catalog(), 'updated_count' => count($items)]);
    }

    public function bulkUpdateUsageRates(Request $request, AuditService $audit): JsonResponse
    {
        $rules = [
            'items' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'items.*' => ['required', 'array:id,label,unit,price_idr,price_usd,is_active,sort_order', 'min:2'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
        ];
        foreach ($this->rateRules() as $field => $rule) {
            $rules['items.*.'.$field] = $rule;
        }
        $items = $request->validate($rules)['items'];
        $rates = $this->persistRates($request, $items, $audit);

        return response()->json(['usage_rates' => $rates->values(), 'updated_count' => count($items)]);
    }

    public function bulkDestroyUsageRates(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_count' => ['required', 'integer', 'min:1', 'max:200'],
        ]);
        $this->assertExactCount(count($validated['ids']), (int) $validated['expected_count']);
        $result = $this->deleteRates($request, $validated['ids'], $audit);

        return response()->json($result);
    }

    private function rateRules(bool $partial = true): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'label' => [$required, 'required', 'string', 'max:160'],
            'unit' => [$required, 'required', 'string', 'max:40'],
            'price_idr' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'price_usd' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'is_active' => [$required, 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    private function durationRules(bool $partial = true): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'price_idr' => [$required, 'integer', 'min:1', 'max:4294967295'],
            'price_usd' => [$required, 'numeric', 'min:0.01', 'max:999999.99'],
            'is_active' => [$required, 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    private function persistDurations(Request $request, array $items, AuditService $audit)
    {
        $prices = DB::transaction(function () use ($request, $items, $audit) {
            $defaults = [];
            foreach (DurationPackagePrice::catalog() as $package => $values) {
                $defaults[] = [
                    'package' => $package, 'price_idr' => $values['price_idr'], 'price_usd' => $values['price_usd'],
                    'is_active' => $values['is_active'], 'sort_order' => $values['sort_order'],
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            // Materialize the fallback plans so simultaneous edits lock the same final state.
            DurationPackagePrice::query()->insertOrIgnore($defaults);
            $prices = DurationPackagePrice::query()->whereIn('package', array_keys(DurationOrder::PACKAGES))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('package');
            $before = [];
            foreach ($items as $item) {
                $price = $prices->get($item['package']);
                $before[$price->package] = $price->toArray();
                $changes = $item;
                unset($changes['package']);
                if (array_key_exists('sort_order', $changes)) {
                    $changes['sort_order'] = $changes['sort_order'] ?? $price->sort_order;
                }
                $price->fill($changes);
            }
            if (! $prices->contains(fn (DurationPackagePrice $price): bool => $price->is_active)) {
                throw ValidationException::withMessages(['items' => 'At least one duration package must remain active.']);
            }
            foreach ($items as $item) {
                $price = $prices->get($item['package']);
                $price->save();
                $audit->record($request->user(), 'pricing.duration.updated', $price, ['before' => $before[$price->package], 'after' => $price->toArray()]);
            }

            return $prices->values();
        });
        Cache::forget('public-model-catalog-v3');

        return $prices;
    }

    private function persistRates(Request $request, array $items, AuditService $audit, bool $creating = false)
    {
        try {
            $rates = DB::transaction(function () use ($request, $items, $audit, $creating) {
                $ids = array_column($items, 'id');
                $identities = $creating ? collect([$items[0]]) : UsageRate::query()->whereKey($ids)->get(['id', 'model', 'service']);
                if (! $creating) {
                    $this->assertExactCount($identities->count(), count($ids));
                }
                $models = $identities->pluck('model')->unique()->values();
                AiModelProfile::query()->whereIn('model_id', $models)->orderBy('id')->lockForUpdate()->get(['id']);
                $rates = UsageRate::query()->whereIn('model', $models)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if (! $creating) {
                    $this->assertExactCount($rates->only($ids)->count(), count($ids));
                }
                $before = $rates->map(fn (UsageRate $rate): array => $rate->toArray())->all();
                $activation = [];
                $explicitActivation = [];
                $groups = [];
                foreach ($items as $index => $item) {
                    $rate = $creating ? new UsageRate : $rates->get($item['id']);
                    $changes = $item;
                    unset($changes['id']);
                    if (array_key_exists('sort_order', $changes)) {
                        $changes['sort_order'] = $changes['sort_order'] ?? 0;
                    }
                    if (array_key_exists('is_active', $changes)) {
                        $explicitActivation[$creating ? 'new' : $rate->id] = ['value' => (bool) $changes['is_active'], 'index' => $index];
                    }
                    $rate->fill($changes);
                    if ($creating) {
                        $rate->sort_order ??= 0;
                        if ($rates->contains(fn (UsageRate $other): bool => $other->service === $rate->service && $other->meter === $rate->meter && $other->model === $rate->model)) {
                            throw ValidationException::withMessages(['model' => 'A rate already exists for this model and meter.']);
                        }
                        $rates->put('new', $rate);
                    }
                    $group = $rate->service.'|'.$rate->model;
                    $groups[$group] = $index;
                    if ($rate->service === 'api' && in_array($rate->meter, ['input_tokens', 'output_tokens'], true) && array_key_exists('is_active', $changes)) {
                        $enabled = (bool) $changes['is_active'];
                        if (array_key_exists($group, $activation) && $activation[$group] !== $enabled) {
                            throw ValidationException::withMessages(["items.{$index}.is_active" => 'Input and output rates must be published or unpublished together.']);
                        }
                        $activation[$group] = $enabled;
                    }
                }
                foreach ($rates as $key => $rate) {
                    $group = $rate->service.'|'.$rate->model;
                    if (($activation[$group] ?? null) === false && ($explicitActivation[$key]['value'] ?? false)) {
                        $index = $explicitActivation[$key]['index'];
                        throw ValidationException::withMessages(["items.{$index}.is_active" => 'An API cache rate cannot remain active while its input/output pair is unpublished.']);
                    }
                    if (array_key_exists($group, $activation)
                        && (! $activation[$group] || in_array($rate->meter, ['input_tokens', 'output_tokens'], true))) {
                        $rate->is_active = $activation[$group];
                    }
                }
                foreach ($groups as $group => $index) {
                    UsageRate::assertValidState($rates->filter(fn (UsageRate $rate): bool => $rate->service.'|'.$rate->model === $group), "items.{$index}.rates");
                }
                foreach ($rates as $rate) {
                    if (! $rate->exists || $rate->isDirty()) {
                        $old = $rate->exists ? $before[$rate->id] : null;
                        $rate->save();
                        $audit->record($request->user(), 'pricing.rate.updated', $rate, ['before' => $old, 'after' => $rate->toArray()]);
                    }
                }

                return $rates->values();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['model' => 'A rate already exists for this model and meter.']);
        }
        Cache::forget('public-model-catalog-v3');

        return $rates;
    }

    private function deleteRates(Request $request, array $ids, AuditService $audit): array
    {
        $result = DB::transaction(function () use ($request, $ids, $audit): array {
            $models = UsageRate::query()->whereKey($ids)->pluck('model')->unique();
            AiModelProfile::query()->whereIn('model_id', $models)->orderBy('id')->lockForUpdate()->get(['id']);
            $rates = UsageRate::query()->whereIn('model', $models)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $selected = $rates->only($ids);
            $this->assertExactCount($selected->count(), count($ids));
            $remaining = $rates->except($ids);
            $deactivated = [];
            foreach ($selected->where('service', 'api')->pluck('model')->unique() as $model) {
                $siblings = $remaining->where('service', 'api')->where('model', $model);
                $active = $siblings->where('is_active', true)->keyBy('meter');
                if ($active->isNotEmpty() && (! $active->has('input_tokens') || ! $active->has('output_tokens'))) {
                    foreach ($siblings as $sibling) {
                        if ($sibling->is_active) {
                            $sibling->is_active = false;
                            $sibling->save();
                            $deactivated[] = $sibling->id;
                            $audit->record($request->user(), 'pricing.rate.unpublished', $sibling, ['reason' => 'Required API sibling deleted']);
                        }
                    }
                }
                UsageRate::assertValidState($siblings);
            }
            foreach ($selected as $rate) {
                $audit->record($request->user(), 'pricing.rate.deleted', $rate, ['before' => $rate->toArray()]);
            }
            UsageRate::query()->whereKey($ids)->delete();

            return ['deleted_count' => count($ids), 'ids' => array_values($ids), 'deactivated_ids' => $deactivated];
        });
        Cache::forget('public-model-catalog-v3');

        return $result;
    }

    private function assertExactCount(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new HttpResponseException(response()->json([
                'message' => 'The selected rows changed. Refresh and confirm the exact selection again.',
            ], 409));
        }
    }

    public function wallet(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'balance_microusd' => Wallet::balance($userId),
            'balance_usd' => Wallet::balance($userId) / 1_000_000,
        ]);
    }
}
