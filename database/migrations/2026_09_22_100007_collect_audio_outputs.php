<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audio_jobs', 'outputs')) {
            Schema::table('audio_jobs', function (Blueprint $table): void {
                $table->json('outputs')->nullable();
            });
        }

        // Preserve historical files in place; their exact private path travels with the
        // first output. Never fetch provider URLs or regenerate already-paid audio.
        if (Schema::hasColumn('audio_jobs', 'audio_path')) {
            DB::table('audio_jobs')->whereNull('outputs')->orderBy('id')->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $outputs = [];
                    if (is_string($job->audio_path) && $job->audio_path !== '') {
                        $expected = 'generated/audio/'.hash('sha256', $job->job_id).'/output.audio';
                        if ($job->audio_path !== $expected
                            || ! in_array($job->mime_type, ['audio/wav', 'audio/mpeg', 'audio/flac', 'audio/ogg'], true)
                            || $job->size_bytes === null || $job->size_bytes < 0) {
                            throw new RuntimeException('Audio job '.$job->id.' has invalid historical asset metadata; repair it before migrating.');
                        }
                        $outputs[] = ['path' => $job->audio_path, 'mime_type' => $job->mime_type, 'size_bytes' => (int) $job->size_bytes];
                    }
                    DB::table('audio_jobs')->where('id', $job->id)->update(['outputs' => json_encode($outputs, JSON_THROW_ON_ERROR)]);
                }
            });
            Schema::table('audio_jobs', function (Blueprint $table): void {
                $table->dropColumn(['audio_url', 'audio_path', 'mime_type', 'size_bytes']);
            });
        }
    }

    public function down(): void
    {
        // The old application cannot serve indexed/multi-track output. Refuse a lossy
        // downgrade after new results exist instead of quietly dropping paid tracks.
        DB::table('audio_jobs')->orderBy('id')->chunkById(100, function ($jobs): void {
            foreach ($jobs as $job) {
                $outputs = json_decode($job->outputs ?? '[]', true, 512, JSON_THROW_ON_ERROR);
                $legacy = 'generated/audio/'.hash('sha256', $job->job_id).'/output.audio';
                if (count($outputs) > 1 || (isset($outputs[0]) && $outputs[0]['path'] !== $legacy)) {
                    throw new RuntimeException('Cannot downgrade audio outputs after indexed results exist. Preserve the collection schema.');
                }
            }
        });
        Schema::table('audio_jobs', function (Blueprint $table): void {
            $table->text('audio_url')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
        });
        DB::table('audio_jobs')->orderBy('id')->chunkById(100, function ($jobs): void {
            foreach ($jobs as $job) {
                $output = json_decode($job->outputs ?? '[]', true, 512, JSON_THROW_ON_ERROR)[0] ?? null;
                if ($output !== null) {
                    DB::table('audio_jobs')->where('id', $job->id)->update([
                        'audio_url' => '/api/audio/'.$job->job_id.'/asset', 'audio_path' => $output['path'],
                        'mime_type' => $output['mime_type'], 'size_bytes' => $output['size_bytes'],
                    ]);
                }
            }
        });
        Schema::table('audio_jobs', fn (Blueprint $table) => $table->dropColumn('outputs'));
    }
};
