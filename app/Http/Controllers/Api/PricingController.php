<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DurationPackagePrice;
use App\Models\UsageRate;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingController extends Controller
{
    private const SERVICES = ['api', 'image', 'video'];

    private const METERS = ['input_tokens', 'output_tokens', 'unit'];

    public function index(): JsonResponse
    {
        return response()->json([
            'duration_packages' => DurationPackagePrice::catalog(),
            'usage_rates' => UsageRate::query()->orderBy('sort_order')->orderBy('label')->get(),
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

    public function saveDuration(Request $request, string $package): JsonResponse
    {
        $catalog = DurationPackagePrice::catalog();
        abort_unless(isset($catalog[$package]), 404);
        $validated = $request->validate([
            'price_idr' => 'required|integer|min:1',
            'price_usd' => 'required|numeric|min:0.01|max:999999.99',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);
        if (! $validated['is_active']) {
            $otherActive = collect($catalog)
                ->except($package)
                ->contains(fn (array $item): bool => $item['is_active']);
            if (! $otherActive) {
                return response()->json(['message' => 'At least one duration package must remain active.'], 422);
            }
        }

        $price = DurationPackagePrice::updateOrCreate(
            ['package' => $package],
            [...$validated, 'sort_order' => $validated['sort_order'] ?? $catalog[$package]['sort_order']],
        );

        return response()->json(['duration_package' => $price]);
    }

    public function saveUsageRate(Request $request, ?UsageRate $usageRate = null): JsonResponse
    {
        if ($usageRate?->exists) {
            $request->merge([
                'service' => $usageRate->service,
                'meter' => $usageRate->meter,
                'model' => $usageRate->model,
            ]);
        }

        $validated = $request->validate([
            'service' => ['required', Rule::in(self::SERVICES)],
            'meter' => ['required', Rule::in(self::METERS)],
            'model' => [
                'required',
                'string',
                'max:120',
                Rule::unique('usage_rates', 'model')
                    ->where(fn ($query) => $query
                        ->where('service', $request->input('service'))
                        ->where('meter', $request->input('meter')))
                    ->ignore($usageRate?->getKey()),
            ],
            'label' => 'required|string|max:160',
            'unit' => 'required|string|max:40',
            'price_idr' => 'nullable|numeric|min:0|max:999999999999',
            'price_usd' => 'nullable|numeric|min:0|max:1000000',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);

        if ($validated['service'] === 'api' && ! in_array($validated['meter'], ['input_tokens', 'output_tokens'], true)) {
            return response()->json(['message' => 'API rates must use input_tokens or output_tokens.'], 422);
        }
        if (in_array($validated['service'], ['image', 'video'], true) && $validated['meter'] !== 'unit') {
            return response()->json(['message' => 'Image and video rates must use unit.'], 422);
        }
        if ($validated['is_active'] && ($validated['price_idr'] === null || $validated['price_usd'] === null)) {
            return response()->json(['message' => 'Active rates require both IDR and USD prices.'], 422);
        }

        $rate = DB::transaction(function () use ($usageRate, $validated): UsageRate {
            $rate = $usageRate ?? new UsageRate;
            if ($rate->exists) {
                $validated['service'] = $rate->service;
                $validated['meter'] = $rate->meter;
                $validated['model'] = $rate->model;
            }
            $rate->fill([...$validated, 'sort_order' => $validated['sort_order'] ?? 0]);
            $rate->save();

            if ($rate->service === 'api') {
                $siblingMeter = $rate->meter === 'input_tokens' ? 'output_tokens' : 'input_tokens';
                $sibling = UsageRate::where('service', 'api')
                    ->where('model', $rate->model)
                    ->where('meter', $siblingMeter)
                    ->first();
                if ($rate->is_active && ! $sibling) {
                    throw ValidationException::withMessages([
                        'is_active' => 'Create both input and output rates before activation.',
                    ]);
                }
                $sibling?->update(['is_active' => $rate->is_active]);
            }

            return $rate;
        });

        return response()->json(['usage_rate' => $rate], $usageRate ? 200 : 201);
    }

    public function destroyUsageRate(UsageRate $usageRate): JsonResponse
    {
        DB::transaction(function () use ($usageRate): void {
            if ($usageRate->service === 'api') {
                UsageRate::where('service', 'api')
                    ->where('model', $usageRate->model)
                    ->whereKeyNot($usageRate->id)
                    ->update(['is_active' => false]);
            }
            $usageRate->delete();
        });

        return response()->json(['message' => 'Usage rate deleted.']);
    }

    public function wallet(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'balance_microusd' => Wallet::balance($userId),
            'balance_usd' => Wallet::balance($userId) / 1_000_000,
        ]);
    }

    public function topupWallet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount_usd' => 'required|numeric|min:0.01|max:1000000',
            'description' => 'nullable|string|max:255',
        ]);
        $amountMicrousd = (int) round((float) $validated['amount_usd'] * 1_000_000);
        $balance = Wallet::credit(
            (int) $validated['user_id'],
            $amountMicrousd,
            $validated['description'] ?? 'Usage wallet top-up by admin',
        );

        return response()->json([
            'balance_microusd' => $balance,
            'balance_usd' => $balance / 1_000_000,
        ]);
    }
}
