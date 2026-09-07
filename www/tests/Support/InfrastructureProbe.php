<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class InfrastructureProbe implements ShouldQueue
{
    public function __construct(public string $key) {}

    public function handle(): void
    {
        Cache::store('database')->put($this->key, 'processed', 60);
    }
}
