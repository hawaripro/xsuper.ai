<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('media_capabilities')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $definition = json_decode($row->definition, true, 512, JSON_THROW_ON_ERROR);
                $changed = false;
                foreach ($definition['params'] ?? [] as $index => $param) {
                    $type = match ($param['type'] ?? null) {
                        'int' => 'integer',
                        'bool' => 'boolean',
                        default => null,
                    };
                    if ($type !== null) {
                        $definition['params'][$index]['type'] = $type;
                        $changed = true;
                    }
                }
                if ($changed) {
                    // Same semantic contract and defaults. Preserve source provenance, hashes,
                    // revision identity and every existing job's normalized input fingerprint.
                    DB::table('media_capabilities')->where('id', $row->id)->update([
                        'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the pre-upgrade database backup to reverse canonical capability parameter types.');
    }
};
