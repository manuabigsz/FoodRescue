<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('foodrescue:expire-shipping-quotations')->everyMinute()->withoutOverlapping();
Schedule::command('foodrescue:expire-unfunded-trades')->everyMinute()->withoutOverlapping();
