<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('wallet/challenge', [WalletController::class, 'registrationChallenge'])
    ->middleware('throttle:registration')->name('wallet.registration_challenge');
Route::post('register', [AuthController::class, 'register'])
    ->middleware('throttle:registration')->name('register');
Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
    Route::post('wallet/change/challenge', [WalletController::class, 'challenge'])
        ->middleware('throttle:sensitive')->name('wallet.challenge');
    Route::post('wallet/surplus-publication-challenge', [WalletController::class, 'surplusPublicationChallenge'])
        ->middleware('throttle:sensitive')->name('wallet.surplus_publication_challenge');
    Route::post('wallet/change/verify', [WalletController::class, 'verify'])
        ->middleware('throttle:sensitive')->name('wallet.verify');
    Route::get('me', [ProfileController::class, 'show'])->name('me');
    Route::patch('me', [ProfileController::class, 'update'])
        ->middleware('throttle:sensitive')->name('profile');
    Route::put('password', [ProfileController::class, 'password'])
        ->middleware('throttle:sensitive')->name('password');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout_all');
});
