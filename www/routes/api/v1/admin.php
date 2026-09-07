<?php

use App\Http\Controllers\Api\AdminCatalogController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminProtocolController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
Route::get('users/{user}', [AdminUserController::class, 'show'])
    ->whereNumber('user')->name('users.show');
Route::post('users', [AdminUserController::class, 'store'])
    ->middleware('throttle:sensitive')->name('users.store');
Route::patch('users/{user}/status', [AdminUserController::class, 'status'])
    ->whereNumber('user')->middleware('throttle:sensitive')->name('users.status');

Route::get('catalog/products', [AdminCatalogController::class, 'products'])->name('catalog.products.index');
Route::post('catalog/products', [AdminCatalogController::class, 'storeProduct'])->middleware('throttle:sensitive')->name('catalog.products.store');
Route::patch('catalog/products/{product}', [AdminCatalogController::class, 'updateProduct'])->whereNumber('product')->middleware('throttle:sensitive')->name('catalog.products.update');
Route::get('catalog/quality-grades', [AdminCatalogController::class, 'qualityGrades'])->name('catalog.quality_grades.index');
Route::post('catalog/quality-grades', [AdminCatalogController::class, 'storeQualityGrade'])->middleware('throttle:sensitive')->name('catalog.quality_grades.store');
Route::patch('catalog/quality-grades/{qualityGrade}', [AdminCatalogController::class, 'updateQualityGrade'])->whereNumber('qualityGrade')->middleware('throttle:sensitive')->name('catalog.quality_grades.update');

Route::get('settings/timeouts', [AdminSettingsController::class, 'show'])->name('settings.timeouts.show');
Route::patch('settings/timeouts', [AdminSettingsController::class, 'update'])->middleware('throttle:sensitive')->name('settings.timeouts.update');

Route::post('blockchain/protocol/prepare', [AdminProtocolController::class, 'prepare'])->middleware(['throttle:sensitive', 'permission:blockchain.protocol.manage'])->name('blockchain.protocol.prepare');
Route::post('blockchain/protocol/confirm', [AdminProtocolController::class, 'confirm'])->middleware(['throttle:sensitive', 'permission:blockchain.protocol.manage'])->name('blockchain.protocol.confirm');

Route::get('dashboard', [AdminDashboardController::class, 'show'])->name('dashboard.show');
Route::get('impact', [AdminDashboardController::class, 'impact'])->name('impact.show');
