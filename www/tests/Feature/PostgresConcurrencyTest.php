<?php

namespace Tests\Feature;

use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_buyers_block_on_the_same_postgres_row_and_only_one_reserves_it(): void
    {
        $this->assertSame('17', explode('.', DB::selectOne('SHOW server_version')->server_version)[0]);
        $lot = SurplusLot::factory()->create();
        $buyers = User::factory()->withRole(UserRole::Buyer)->count(2)->create();
        $processes = [];
        DB::beginTransaction();
        SurplusLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($buyers as $buyer) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/reserve_lot.php'), (string) $buyer->id, (string) $lot->id], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'food_rescue_api_test',
                    'DB_URL' => '', 'CACHE_STORE' => 'array',
                ]);
                $process->setTimeout(20)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT COUNT(*) AS n FROM pg_stat_activity WHERE application_name = 'foodrescue_reservation_test' AND wait_event_type = 'Lock'")->n;
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both independent database sessions must contend for the locked lot. '.implode(' ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $processes)));
            DB::commit();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([201, 409], $statuses);
            $this->assertSame(1, Trade::where('surplus_lot_id', $lot->id)->count());
            $this->assertSame('reserved', $lot->fresh()->status->value);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
