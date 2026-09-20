<?php

namespace App\Services;

use App\Models\AiModelProfile;
use App\Models\UsageRate;
use App\Models\User;

/**
 * Fills the per-token input/output prices a chat model needs before it can be
 * billed. Both meters must exist and be active (UsageBillingService::apiRates),
 * so they are always written as a pair — leaving one side blank produced models
 * that looked published but refused every request.
 *
 * Shared by the admin "auto pricing" action and by provider sync, so freshly
 * discovered models arrive usable instead of waiting for manual data entry.
 */
class ModelAutoPricer
{
    /** Retail USD per 1M tokens by tier, before the margin multiplier. */
    private const BASE = [
        'Standard' => [0.15, 0.60],
        'MAX' => [3.00, 15.00],
    ];

    public function __construct(private readonly AuditService $audit) {}

    public static function tierFor(?string $tier): string
    {
        return match ($tier) {
            'Authentic', 'MAX' => 'MAX',
            default => 'Standard',
        };
    }

    /**
     * @param  iterable<AiModelProfile>  $models
     * @return int number of rate rows written
     */
    public function price(
        iterable $models,
        float $margin = 1.0,
        float $idrPerUsd = 16000,
        bool $overwrite = false,
        ?User $actor = null,
        string $event = 'pricing.rate.auto',
    ): int {
        $count = 0;
        foreach ($models as $model) {
            if ($model->category !== 'chat') {
                continue;
            }
            [$inUsd, $outUsd] = self::BASE[self::tierFor($model->tier)];
            foreach (['input_tokens' => $inUsd, 'output_tokens' => $outUsd] as $meter => $usd) {
                $rate = UsageRate::query()
                    ->where(['service' => 'api', 'meter' => $meter, 'model' => $model->model_id])
                    ->first();
                if ($rate && ! $overwrite) {
                    continue;
                }
                $price = round($usd * $margin, 6);
                $before = $rate?->toArray();
                $rate ??= new UsageRate(['service' => 'api', 'meter' => $meter, 'model' => $model->model_id]);
                $rate->fill([
                    'label' => $model->display_name.' '.str_replace('_', ' ', $meter),
                    'unit' => '1M tokens',
                    'price_usd' => $price,
                    'price_idr' => round($price * $idrPerUsd, 6),
                    'is_active' => true,
                    'sort_order' => $rate->sort_order ?? (($model->sort_order ?? 0) * 10),
                ]);
                if (! $rate->exists || $rate->isDirty()) {
                    $rate->save();
                    $this->audit->record($actor, $event, $rate, ['before' => $before, 'after' => $rate->toArray()]);
                    $count++;
                }
            }
        }

        return $count;
    }
}
