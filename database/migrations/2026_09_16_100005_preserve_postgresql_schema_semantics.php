<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Preserve the accepted MySQL unsigned domains instead of silently narrowing them.
    // Laravel identifiers remain signed 64-bit, its application-supported identity range.
    private const UNSIGNED = [
        'ai_model_profiles' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'provider_id' => ['bigint', '9223372036854775807', 'bigint'], 'context_window' => ['bigint', '4294967295', 'integer'], 'max_output_tokens' => ['bigint', '4294967295', 'integer'], 'token_cost' => ['bigint', '4294967295', 'integer'], 'sort_order' => ['integer', '65535', 'smallint']],
        'ai_provider_profiles' => ['id' => ['bigint', '9223372036854775807', 'bigint']],
        'analytics_events' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'api_keys' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'audit_events' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'actor_id' => ['bigint', '9223372036854775807', 'bigint'], 'subject_id' => ['bigint', '9223372036854775807', 'bigint']],
        'chat_conversations' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'chat_history' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'chat_messages' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'conversation_id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'content_blocks' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'updated_by' => ['bigint', '9223372036854775807', 'bigint']],
        'deposit_orders' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'base_tokens' => ['bigint', '4294967295', 'integer'], 'bonus_tokens' => ['bigint', '4294967295', 'integer'], 'total_tokens' => ['bigint', '4294967295', 'integer'], 'amount_idr' => ['bigint', '4294967295', 'integer'], 'credit_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint'], 'idr_per_usd' => ['bigint', '4294967295', 'integer'], 'approved_by' => ['bigint', '9223372036854775807', 'bigint'], 'rejected_by' => ['bigint', '9223372036854775807', 'bigint']],
        'duration_orders' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'approved_by' => ['bigint', '9223372036854775807', 'bigint']],
        'duration_package_prices' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'price_idr' => ['bigint', '4294967295', 'integer'], 'sort_order' => ['integer', '65535', 'smallint']],
        'failed_jobs' => ['id' => ['bigint', '9223372036854775807', 'bigint']],
        'feedback' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'rating' => ['smallint', '255', 'smallint']],
        'image_jobs' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'quantity' => ['smallint', '255', 'smallint'], 'billing_reserved_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint'], 'tokens_reserved' => ['bigint', '4294967295', 'integer']],
        'jobs' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'attempts' => ['integer', '65535', 'smallint'], 'reserved_at' => ['bigint', '4294967295', 'integer'], 'available_at' => ['bigint', '4294967295', 'integer'], 'created_at' => ['bigint', '4294967295', 'integer']],
        'migrations' => ['id' => ['bigint', '4294967295', 'integer']],
        'notifications' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'payment_checkouts' => ['user_id' => ['bigint', '9223372036854775807', 'bigint'], 'amount_idr' => ['bigint', '4294967295', 'integer']],
        'prompt_templates' => ['id' => ['bigint', '9223372036854775807', 'bigint']],
        'referrals' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'referrer_id' => ['bigint', '9223372036854775807', 'bigint'], 'referred_id' => ['bigint', '9223372036854775807', 'bigint']],
        'referral_program_settings' => ['id' => ['smallint', '255', 'smallint'], 'reward_days' => ['integer', '65535', 'smallint'], 'updated_by' => ['bigint', '9223372036854775807', 'bigint']],
        'referral_rewards' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'referral_id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'days' => ['bigint', '4294967295', 'integer'], 'wallet_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint']],
        'sessions' => ['user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'support_messages' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'ticket_id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'support_tickets' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'assigned_to' => ['bigint', '9223372036854775807', 'bigint']],
        'token_packages' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'base_tokens' => ['bigint', '4294967295', 'integer'], 'bonus_tokens' => ['bigint', '4294967295', 'integer'], 'price_idr' => ['bigint', '4294967295', 'integer'], 'sort_order' => ['integer', '65535', 'smallint']],
        'token_reservations' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'quantity' => ['bigint', '4294967295', 'integer'], 'unit_tokens' => ['bigint', '4294967295', 'integer'], 'amount_tokens' => ['numeric(20,0)', '18446744073709551615', 'bigint']],
        'token_transactions' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'usage_logs' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'cost_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint'], 'usage_rate_id' => ['bigint', '9223372036854775807', 'bigint']],
        'usage_rates' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'sort_order' => ['integer', '65535', 'smallint']],
        'users' => ['id' => ['bigint', '9223372036854775807', 'bigint']],
        'user_devices' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'user_tokens' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint']],
        'video_jobs' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'billing_reserved_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint'], 'provider_id' => ['bigint', '9223372036854775807', 'bigint'], 'tokens_reserved' => ['bigint', '4294967295', 'integer'], 'poll_attempts' => ['bigint', '4294967295', 'integer']],
        'wallets' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'balance_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint']],
        'wallet_transactions' => ['id' => ['bigint', '9223372036854775807', 'bigint'], 'user_id' => ['bigint', '9223372036854775807', 'bigint'], 'balance_after_microusd' => ['numeric(20,0)', '18446744073709551615', 'bigint'], 'quantity' => ['numeric(20,0)', '18446744073709551615', 'bigint']],
    ];

    private const CASE_INSENSITIVE = [
        'ai_model_profiles' => ['model_id', 'display_name', 'provider_name', 'category', 'tier', 'description_id', 'description_en', 'logo_url', 'upstream_model_id'],
        'ai_provider_profiles' => ['slug', 'name', 'status', 'last_error', 'protocol', 'base_url', 'api_key', 'api_version'],
        'analytics_events' => ['name', 'session_id', 'path'],
        'api_keys' => ['key', 'name'],
        'audit_events' => ['action', 'subject_type', 'ip_address', 'user_agent'],
        'cache' => ['key', 'value'],
        'cache_locks' => ['key', 'owner'],
        'chat_conversations' => ['title', 'model'],
        'chat_history' => ['conversation_id', 'role', 'content', 'model'],
        'chat_messages' => ['role', 'content', 'model'],
        'content_blocks' => ['key', 'locale'],
        'deposit_orders' => ['payment_reference', 'kind', 'package_code', 'package_name', 'payment_method', 'status', 'note'],
        'duration_orders' => ['package', 'payment_method', 'payment_reference', 'status', 'note'],
        'duration_package_prices' => ['package'],
        'failed_jobs' => ['uuid', 'connection', 'queue', 'payload', 'exception'],
        'feedback' => ['category', 'message', 'status', 'admin_note'],
        'image_jobs' => ['job_id', 'model', 'prompt', 'size', 'status', 'stage', 'error_message', 'billing_reference_id', 'billing_status', 'billing_mode'],
        'jobs' => ['queue', 'payload'],
        'job_batches' => ['id', 'name', 'failed_job_ids', 'options'],
        'migrations' => ['migration'],
        'notifications' => ['kind', 'title', 'body', 'action_url'],
        'password_reset_tokens' => ['email', 'token'],
        'payment_checkouts' => ['reference', 'package', 'payment_method'],
        'prompt_templates' => ['category', 'title', 'prompt_text', 'mode', 'template_key'],
        'referrals' => ['code', 'status'],
        'referral_rewards' => ['kind', 'reference'],
        'sessions' => ['id', 'ip_address', 'user_agent', 'payload'],
        'support_messages' => ['body'],
        'support_tickets' => ['subject', 'category', 'priority', 'status'],
        'token_packages' => ['code', 'name'],
        'token_reservations' => ['reference_id', 'service', 'model', 'billing_mode', 'status', 'release_description'],
        'token_transactions' => ['type', 'description', 'reference_id'],
        'usage_logs' => ['model', 'source', 'device_id'],
        'usage_rates' => ['service', 'meter', 'model', 'label', 'unit'],
        'users' => ['name', 'email', 'referral_code', 'google_id', 'onboarding_mode', 'role', 'avatar', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'],
        'user_devices' => ['device_hash', 'device_name', 'device_type', 'user_agent', 'ip_address', 'status'],
        'video_jobs' => ['job_id', 'mode', 'prompt', 'model', 'aspect_ratio', 'billing_reference_id', 'billing_status', 'status', 'video_url', 'thumbnail_url', 'error_message', 'upstream_model_id', 'upstream_job_id', 'connection_fingerprint', 'stage', 'improved_prompt', 'moderation_reason_code', 'billing_mode'],
        'wallet_transactions' => ['type', 'service', 'model', 'meter', 'reference_id', 'description'],
    ];

    private const INDEX_PATHS = [
        'analytics_events' => [['user_id']],
        'audit_events' => [['actor_id']],
        'chat_conversations' => [['user_id']],
        'chat_messages' => [['conversation_id'], ['user_id']],
        'content_blocks' => [['updated_by']],
        'deposit_orders' => [['approved_by'], ['rejected_by']],
        'duration_orders' => [['approved_by'], ['user_id']],
        'feedback' => [['user_id']],
        'referral_program_settings' => [['updated_by']],
        'referral_rewards' => [['referral_id'], ['user_id']],
        'support_messages' => [['ticket_id'], ['user_id']],
        'support_tickets' => [['assigned_to'], ['user_id']],
        'usage_logs' => [['usage_rate_id']],
    ];

    private const AUTO_TIMESTAMPS = [
        'payment_checkouts' => 'expires_at',
        'deposit_orders' => 'expires_at',
        'referrals' => 'attributed_at',
        'referral_rewards' => 'awarded_at',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        if ((int) DB::scalar("SELECT current_setting('server_version_num')") < 180000) {
            throw new RuntimeException('PostgreSQL 18 or newer is required for case-insensitive LIKE compatibility.');
        }
        DB::statement("CREATE COLLATION IF NOT EXISTS public.ultrai_unicode_ci (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");
        $this->dropGeneratedIdentity();
        foreach (self::UNSIGNED as $table => $columns) {
            foreach ($columns as $column => [$type, $maximum]) {
                $target = $this->quote($table);
                $field = $this->quote($column);
                DB::statement("ALTER TABLE {$target} ALTER COLUMN {$field} TYPE {$type}");
                $name = $this->quote($this->constraintName($table, $column));
                DB::statement("ALTER TABLE {$target} ADD CONSTRAINT {$name} CHECK ({$field} >= 0 AND {$field} <= {$maximum})");
            }
        }
        $this->collate('public.ultrai_unicode_ci');
        $this->addGeneratedIdentity('public.ultrai_unicode_ci');
        foreach (self::INDEX_PATHS as $table => $paths) {
            foreach ($paths as $columns) {
                $name = $this->quote($this->indexName($table, $columns));
                $fields = implode(', ', array_map($this->quote(...), $columns));
                DB::statement('CREATE INDEX '.$name.' ON '.$this->quote($table).' ('.$fields.')');
            }
        }
        DB::statement('ALTER SEQUENCE migrations_id_seq AS bigint MAXVALUE 4294967295');
        $this->foreignUpdateAction('restrict');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ultrai_timestamp_update() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE marker text := TG_RELID::text || ':' || TG_ARGV[0];
BEGIN
    IF TG_ARGV[1] = 'explicit' THEN
        PERFORM set_config('ultrai.timestamp_explicit', marker, true);
        RETURN NEW;
    END IF;
    IF current_setting('ultrai.timestamp_explicit', true) IS NOT DISTINCT FROM marker THEN
        PERFORM set_config('ultrai.timestamp_explicit', '', true);
        RETURN NEW;
    END IF;
    IF to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD) THEN
        NEW := jsonb_populate_record(NEW, jsonb_build_object(TG_ARGV[0], CURRENT_TIMESTAMP(0) AT TIME ZONE 'UTC'));
    END IF;
    RETURN NEW;
END;
$$
SQL);
        foreach (self::AUTO_TIMESTAMPS as $table => $column) {
            $target = $this->quote($table);
            $field = $this->quote($column);
            DB::statement("ALTER TABLE {$target} ALTER COLUMN {$field} SET DEFAULT CURRENT_TIMESTAMP(0)");
            DB::statement("CREATE TRIGGER ultrai_timestamp_a_explicit BEFORE UPDATE OF {$field} ON {$target} FOR EACH ROW EXECUTE FUNCTION public.ultrai_timestamp_update('{$column}', 'explicit')");
            DB::statement("CREATE TRIGGER ultrai_timestamp_z_implicit BEFORE UPDATE ON {$target} FOR EACH ROW EXECUTE FUNCTION public.ultrai_timestamp_update('{$column}', 'implicit')");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::UNSIGNED as $table => $columns) {
            foreach ($columns as $column => [$type, $maximum, $previous]) {
                $oldMaximum = match ($previous) {
                    'smallint' => '32767',
                    'integer' => '2147483647',
                    default => '9223372036854775807',
                };
                if (DB::table($table)->where($column, '>', $oldMaximum)->exists()) {
                    throw new RuntimeException("Cannot narrow PostgreSQL column {$table}.{$column}; values exceed its previous range.");
                }
            }
        }
        foreach (self::AUTO_TIMESTAMPS as $table => $column) {
            $target = $this->quote($table);
            DB::statement("DROP TRIGGER ultrai_timestamp_a_explicit ON {$target}");
            DB::statement("DROP TRIGGER ultrai_timestamp_z_implicit ON {$target}");
            DB::statement('ALTER TABLE '.$target.' ALTER COLUMN '.$this->quote($column).' DROP DEFAULT');
        }
        DB::statement('DROP FUNCTION public.ultrai_timestamp_update()');
        $this->foreignUpdateAction('no action');
        $this->dropGeneratedIdentity();
        foreach (self::INDEX_PATHS as $table => $paths) {
            foreach ($paths as $columns) {
                DB::statement('DROP INDEX '.$this->quote($this->indexName($table, $columns)));
            }
        }
        foreach (self::UNSIGNED as $table => $columns) {
            foreach ($columns as $column => [$type, $maximum, $previous]) {
                $target = $this->quote($table);
                $field = $this->quote($column);
                DB::statement('ALTER TABLE '.$target.' DROP CONSTRAINT '.$this->quote($this->constraintName($table, $column)));
                DB::statement("ALTER TABLE {$target} ALTER COLUMN {$field} TYPE {$previous} USING {$field}::{$previous}");
            }
        }
        $this->collate('"default"');
        $this->addGeneratedIdentity('"default"');
        DB::statement('ALTER SEQUENCE migrations_id_seq AS integer MAXVALUE 2147483647');
        DB::statement('DROP COLLATION public.ultrai_unicode_ci');
    }

    private function foreignUpdateAction(string $action): void
    {
        $previous = $action === 'restrict' ? 'a' : 'r';
        $constraints = DB::select("SELECT c.conname, t.relname, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE c.contype='f' AND c.confupdtype=? AND n.nspname='public'", [$previous]);
        foreach ($constraints as $constraint) {
            $definition = preg_replace('/ ON UPDATE RESTRICT/', '', $constraint->definition);
            $target = $this->quote($constraint->relname);
            $name = $this->quote($constraint->conname);
            DB::statement("ALTER TABLE {$target} DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE {$target} ADD CONSTRAINT {$name} {$definition} ON UPDATE ".strtoupper($action));
        }
    }

    private function collate(string $collation): void
    {
        foreach (self::CASE_INSENSITIVE as $table => $columns) {
            foreach ($columns as $column) {
                $type = DB::scalar('SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? AND a.attname = ?', ['public', $table, $column]);
                if ($type === 'uuid') {
                    continue;
                }
                if (! is_string($type) || preg_match('/^(text|character varying(?:\(\d+\))?|character\(\d+\))$/D', $type) !== 1) {
                    throw new RuntimeException("Unexpected PostgreSQL text type for {$table}.{$column}.");
                }
                DB::statement('ALTER TABLE '.$this->quote($table).' ALTER COLUMN '.$this->quote($column).' TYPE '.$type.' COLLATE '.$collation);
            }
        }
    }

    private function dropGeneratedIdentity(): void
    {
        DB::statement('ALTER TABLE ai_model_profiles DROP CONSTRAINT ai_models_provider_upstream_unique');
        DB::statement('ALTER TABLE ai_model_profiles DROP COLUMN upstream_identity');
    }

    private function addGeneratedIdentity(string $collation): void
    {
        DB::statement('ALTER TABLE ai_model_profiles ADD COLUMN upstream_identity varchar(160) COLLATE '.$collation.' GENERATED ALWAYS AS (COALESCE(upstream_model_id, model_id)) STORED NOT NULL');
        DB::statement('ALTER TABLE ai_model_profiles ADD CONSTRAINT ai_models_provider_upstream_unique UNIQUE (provider_id, upstream_identity)');
    }

    private function constraintName(string $table, string $column): string
    {
        return 'ultrai_unsigned_'.substr(hash('sha256', $table.'.'.$column), 0, 16);
    }

    private function indexName(string $table, array $columns): string
    {
        return 'ultrai_fk_path_'.substr(hash('sha256', $table.'.'.implode('.', $columns)), 0, 16);
    }

    private function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
};
