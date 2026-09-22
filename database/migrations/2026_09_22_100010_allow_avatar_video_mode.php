<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->modes(['prompt', 'ab_testing', 'avatar']);
    }

    public function down(): void
    {
        if (DB::table('video_jobs')->where('mode', 'avatar')->exists()) {
            throw new RuntimeException('Cannot remove avatar mode while avatar jobs remain. Their history must be preserved.');
        }
        $this->modes(['prompt', 'ab_testing']);
    }

    private function modes(array $values): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Laravel implements enum as varchar + a named CHECK on PostgreSQL.
            DB::statement('ALTER TABLE video_jobs DROP CONSTRAINT IF EXISTS video_jobs_mode_check');
            $quoted = implode(', ', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));
            DB::statement('ALTER TABLE video_jobs ADD CONSTRAINT video_jobs_mode_check CHECK (mode IN ('.$quoted.'))');

            return;
        }

        Schema::table('video_jobs', function (Blueprint $table) use ($values): void {
            $table->enum('mode', $values)->change();
        });
    }
};
