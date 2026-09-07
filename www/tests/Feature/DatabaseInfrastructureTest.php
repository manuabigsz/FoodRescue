<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\InfrastructureProbe;
use Tests\TestCase;

class DatabaseInfrastructureTest extends TestCase
{
    use DatabaseMigrations;

    public function test_database_worker_cache_lock_and_session_round_trip(): void
    {
        $key = 'validation-'.Str::uuid();
        Queue::connection('database')->push(new InfrastructureProbe($key), '', 'validation');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'validation', '--once' => true, '--tries' => 1]);
        $this->assertSame('processed', Cache::store('database')->get($key));
        $lock = Cache::store('database')->lock($key, 30);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::store('database')->lock($key, 30)->get());
        $this->assertTrue($lock->release());
        $handler = new DatabaseSessionHandler(app('db')->connection(), 'sessions', 120, app());
        $handler->write($key, 'session payload');
        $this->assertSame('session payload', $handler->read($key));
        $handler->destroy($key);
        $this->assertSame('', $handler->read($key));
        $this->assertGreaterThan(90, config('queue.connections.database.retry_after'));
    }
}
