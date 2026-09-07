<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('HTTP smoke requires the local application.');
}
$base = 'http://localhost:8080';
foreach (['/' => 200, '/up' => 200, '/api/v1/auth/me' => 401] as $path => $expected) {
    $response = Http::get($base.$path);
    if ($response->status() !== $expected) {
        throw new RuntimeException('Unexpected status for '.$path);
    }
    echo $path.' '.$response->status().PHP_EOL;
}
$credentials = config('accounts.initial_admin');
$response = Http::post($base.'/api/v1/auth/login', [
    'email' => $credentials['email'], 'password' => $credentials['password'], 'device_name' => 'validation-smoke',
]);
if ($response->status() !== 200) {
    throw new RuntimeException('Local admin login failed.');
}
$token = $response->json('data.token');
try {
    foreach (['/api/v1/auth/me', '/api/v1/admin/dashboard', '/api/v1/admin/impact'] as $path) {
        $response = Http::withToken($token)->get($base.$path);
        if ($response->status() !== 200) {
            throw new RuntimeException('Authenticated HTTP smoke failed for '.$path);
        }
        echo $path.' '.$response->status().PHP_EOL;
    }
} finally {
    Http::withToken($token)->send('POST', $base.'/api/v1/auth/logout', ['json' => (object) []]);
}
