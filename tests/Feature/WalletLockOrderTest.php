<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use Throwable;

/**
 * Account transactions lock the owner user row before the wallet row. Membership approval and chat
 * admission already take user → wallet, so a wallet entry point that locks the wallet first deadlocks
 * against them on PostgreSQL and the API request loses its settlement or refund.
 */
class WalletLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private const DETAILS = ['service' => 'api', 'model' => 'lock-order-model', 'meter' => 'request', 'description' => 'API usage: lock-order-model'];

    /**
     * The rest of an owner-first account transaction that already holds the user row: once the wallet call
     * under test is blocked behind it, lock and credit the wallet, then commit. Errors (deadlock victim)
     * are recorded instead of raised so the owner row is always released.
     */
    private const OWNER_THEN_WALLET = <<<'SQL'
        DO $$
        BEGIN
            FOR attempt IN 1..500 LOOP
                EXIT WHEN pg_backend_pid() = ANY (pg_blocking_pids(%1$d));
                PERFORM pg_sleep(0.01);
            END LOOP;
            PERFORM 1 FROM wallets WHERE user_id = %2$d FOR UPDATE;
            UPDATE wallets SET balance_microusd = balance_microusd + 50000 WHERE user_id = %2$d;
            PERFORM set_config('lock_order.outcome', 'credited', false);
        EXCEPTION WHEN OTHERS THEN
            PERFORM set_config('lock_order.outcome', SQLERRM, false);
        END
        $$;
        COMMIT;
        SELECT current_setting('lock_order.outcome', true);
        SQL;

    public static function walletEntryPoints(): array
    {
        return [
            'API reservation' => [static fn (int $userId, array $held) => Wallet::reserve($userId, 100_000, 'api:lock-order-next', self::DETAILS), 550_000],
            'API settlement refund' => [static fn (int $userId, array $held) => Wallet::settle($userId, $held, 150_000, self::DETAILS), 900_000],
            'failed request release' => [static fn (int $userId, array $held) => Wallet::release($userId, $held, 'API upstream request failed'), 1_050_000],
            'deposit credit' => [static fn (int $userId, array $held) => Wallet::credit($userId, 70_000, 'Approved QRIS wallet deposit', 'deposit-order:lock-order', 'deposit'), 720_000],
        ];
    }

    #[DataProvider('walletEntryPoints')]
    #[RequiresPhpExtension('pgsql')]
    public function test_wallet_entry_point_waits_for_an_owner_first_transaction_instead_of_deadlocking(Closure $walletCall, int $expectedBalance): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-lock ordering is only observable on PostgreSQL.');
        }

        $user = User::factory()->create();
        $session = null;
        try {
            Wallet::credit($user->id, 1_000_000, 'Opening deposit', 'deposit-order:lock-order-opening', 'deposit');
            $held = Wallet::reserve($user->id, 400_000, 'api:lock-order-held', self::DETAILS);

            $session = $this->competingSession();
            pg_query($session, 'BEGIN');
            pg_query_params($session, 'SELECT id FROM users WHERE id = $1 FOR UPDATE', [$user->id]);
            pg_send_query($session, sprintf(self::OWNER_THEN_WALLET, DB::scalar('SELECT pg_backend_pid()'), $user->id));

            $failure = null;
            try {
                $walletCall($user->id, $held);
            } catch (Throwable $exception) {
                $failure = $exception->getMessage();
            }

            $this->assertSame(
                ['wallet call' => null, 'owner-first transaction' => 'credited'],
                ['wallet call' => $failure, 'owner-first transaction' => $this->outcome($session)],
            );
            $this->assertSame($expectedBalance, Wallet::balance($user->id));
        } finally {
            if ($session !== null) {
                pg_close($session);
            }
            $user->delete();
        }
    }

    #[RequiresPhpExtension('pgsql')]
    public function test_reading_a_new_account_balance_does_not_wait_for_an_owner_first_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-lock ordering is only observable on PostgreSQL.');
        }

        // No wallet row yet; a first deposit or membership approval holds the owner row.
        $user = User::factory()->create();
        $session = null;
        try {
            $session = $this->competingSession();
            pg_query($session, 'BEGIN');
            pg_query_params($session, 'SELECT id FROM users WHERE id = $1 FOR UPDATE', [$user->id]);

            // A read that inserted the wallet row would hold it while waiting for the owner row.
            $balance = DB::transaction(function () use ($user): int {
                DB::statement("SET LOCAL lock_timeout = '2s'");

                return Wallet::balance($user->id);
            });

            $this->assertSame(0, $balance);
        } finally {
            if ($session !== null) {
                pg_close($session);
            }
            $user->delete();
        }
    }

    /** PostgreSQL commits for real so the competing session sees and locks the same account rows. */
    protected function connectionsToTransact(): array
    {
        return config('database.default') === 'pgsql' ? [] : [config('database.default')];
    }

    private function competingSession(): Connection
    {
        $config = DB::connection()->getConfig();
        $quote = static fn (mixed $value): string => "'".addcslashes((string) $value, "'\\")."'";

        return pg_connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5 options=%s',
            $quote($config['host']), $quote($config['port']), $quote($config['database']),
            $quote($config['username']), $quote($config['password']), $quote('-c lock_timeout=15s'),
        ), PGSQL_CONNECT_FORCE_NEW);
    }

    private function outcome(Connection $session): ?string
    {
        $outcome = null;
        while (($result = pg_get_result($session)) !== false) {
            $error = pg_result_error($result);
            if (is_string($error) && $error !== '') {
                return $error;
            }
            if (pg_num_rows($result) === 1) {
                $outcome = pg_fetch_result($result, 0, 0);
            }
        }

        return $outcome;
    }
}
