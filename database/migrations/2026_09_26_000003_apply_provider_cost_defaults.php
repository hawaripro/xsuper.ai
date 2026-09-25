<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_provider_profiles')->where('protocol', 'kinovi')->whereNull('cost_idr_per_unit')->update([
            'cost_currency' => 'credit', 'cost_idr_per_unit' => '85.8140', 'cost_note' => 'Rp184.500 / 2.150 kredit',
        ]);
        DB::table('ai_provider_profiles')->where('protocol', 'fal')->whereNull('cost_idr_per_unit')->update([
            'cost_currency' => 'usd', 'cost_idr_per_unit' => '19000.0000', 'cost_note' => 'Rp190.000 / $10',
        ]);
    }

    public function down(): void
    {
        foreach (['kinovi' => ['85.8140', 'Rp184.500 / 2.150 kredit'], 'fal' => ['19000.0000', 'Rp190.000 / $10']] as $protocol => [$rate, $note]) {
            DB::table('ai_provider_profiles')->where('protocol', $protocol)->where('cost_idr_per_unit', $rate)->where('cost_note', $note)
                ->update(['cost_currency' => 'usd', 'cost_idr_per_unit' => null, 'cost_note' => null]);
        }
    }
};
