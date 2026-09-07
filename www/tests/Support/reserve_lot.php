<?php

use App\Models\SurplusLot;
use App\Models\User;
use App\Services\Marketplace\SurplusMarketplace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'food_rescue_api_test') {
    exit(2);
}
DB::statement("SET application_name = 'foodrescue_reservation_test'");
try {
    $trade = app(SurplusMarketplace::class)->buyNow(
        User::findOrFail($argv[1]), SurplusLot::findOrFail($argv[2]),
    );
    echo json_encode(['status' => 201, 'trade_id' => $trade->id]);
} catch (HttpException $exception) {
    echo json_encode(['status' => $exception->getStatusCode()]);
}
