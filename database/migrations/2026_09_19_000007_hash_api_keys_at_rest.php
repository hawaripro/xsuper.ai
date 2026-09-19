<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops storing external API bearer keys in plaintext. Adds a sha256 hash column
 * (looked up at auth time) plus a short prefix for masked display, backfills from
 * the existing plaintext values, then drops the plaintext `key` column so a DB or
 * backup leak no longer discloses working credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            if (! Schema::hasColumn('api_keys', 'key_hash')) {
                $table->string('key_hash', 64)->nullable()->after('key');
            }
            if (! Schema::hasColumn('api_keys', 'key_prefix')) {
                $table->string('key_prefix', 20)->nullable()->after('key_hash');
            }
        });

        // Only meaningful while the plaintext column still exists.
        if (Schema::hasColumn('api_keys', 'key')) {
            foreach (DB::table('api_keys')->select('id', 'key')->get() as $row) {
                DB::table('api_keys')->where('id', $row->id)->update([
                    'key_hash' => hash('sha256', (string) $row->key),
                    'key_prefix' => substr((string) $row->key, 0, 12),
                ]);
            }

            Schema::table('api_keys', function (Blueprint $table) {
                // The old plaintext column carried a unique + a plain index; both must
                // go before the column can be dropped (SQLite refuses otherwise).
                $table->dropUnique('api_keys_key_unique');
                $table->dropIndex('api_keys_key_index');
                $table->dropColumn('key');
            });
            Schema::table('api_keys', function (Blueprint $table) {
                $table->unique('key_hash');
            });
        }
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['key_hash']);
            $table->string('key')->nullable()->after('user_id');
        });
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['key_hash', 'key_prefix']);
        });
    }
};
