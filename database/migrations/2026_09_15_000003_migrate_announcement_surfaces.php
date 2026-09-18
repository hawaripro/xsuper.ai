<?php

use App\Models\ContentBlock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_blocks')) {
            return;
        }

        ContentBlock::query()
            ->where('key', 'system.announcement')
            ->each(function (ContentBlock $block): void {
                $updates = [];
                foreach (['draft', 'published'] as $field) {
                    $payload = $block->{$field};
                    if (! is_array($payload)) {
                        continue;
                    }

                    $surfaces = $payload['surfaces'] ?? null;
                    if ($surfaces === null) {
                        $payload['surfaces'] = ['landing'];
                    } elseif (in_array('public', $surfaces, true)) {
                        $payload['surfaces'] = array_values(array_unique([
                            ...array_filter($surfaces, fn (string $surface): bool => $surface !== 'public'),
                            'landing',
                            'pricing',
                            'models',
                        ]));
                    }
                    $updates[$field] = $payload;
                }

                if ($updates !== []) {
                    $block->forceFill($updates)->save();
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_blocks')) {
            return;
        }

        ContentBlock::query()
            ->where('key', 'system.announcement')
            ->each(function (ContentBlock $block): void {
                $updates = [];
                foreach (['draft', 'published'] as $field) {
                    $payload = $block->{$field};
                    if (! is_array($payload)) {
                        continue;
                    }
                    $surfaces = $payload['surfaces'] ?? [];
                    if (array_intersect($surfaces, ['landing', 'pricing', 'models'])) {
                        $payload['surfaces'] = array_values(array_unique([
                            ...array_filter($surfaces, fn (string $surface): bool => ! in_array($surface, ['landing', 'pricing', 'models'], true)),
                            'public',
                        ]));
                    }
                    $updates[$field] = $payload;
                }

                if ($updates !== []) {
                    $block->forceFill($updates)->save();
                }
            });
    }
};
