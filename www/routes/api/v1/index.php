<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(__DIR__.'/auth.php');

Route::prefix('admin')->name('admin.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api', 'can:viewAny,'.User::class])
    ->group(__DIR__.'/admin.php');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(__DIR__.'/marketplace.php');
