<?php

use App\Http\Controllers\Api\BlockchainTradeController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DonationController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\ProtocolController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\RescueProofController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\SurplusLotController;
use App\Http\Controllers\Api\TradeCancellationController;
use App\Http\Controllers\Api\TradeController;
use Illuminate\Support\Facades\Route;

Route::get('catalog/products', [CatalogController::class, 'products'])->name('catalog.products.index');
Route::get('catalog/quality-grades', [CatalogController::class, 'qualityGrades'])->name('catalog.quality_grades.index');

Route::get('surplus', [SurplusLotController::class, 'index'])->name('surplus.index');
Route::get('surplus/{surplusLot}', [SurplusLotController::class, 'show'])->whereNumber('surplusLot')->name('surplus.show');
Route::post('surplus', [SurplusLotController::class, 'store'])->middleware('throttle:sensitive')->name('surplus.store');
Route::patch('surplus/{surplusLot}', [SurplusLotController::class, 'update'])->whereNumber('surplusLot')->middleware('throttle:sensitive')->name('surplus.update');
Route::post('surplus/{surplusLot}/cancel', [SurplusLotController::class, 'cancel'])->whereNumber('surplusLot')->middleware('throttle:sensitive')->name('surplus.cancel');

Route::get('surplus/{surplusLot}/offers', [OfferController::class, 'index'])->whereNumber('surplusLot')->name('offers.index');
Route::post('surplus/{surplusLot}/offers', [OfferController::class, 'store'])->whereNumber('surplusLot')->middleware('throttle:sensitive')->name('offers.store');
Route::post('offers/{offer}/accept', [OfferController::class, 'accept'])->whereNumber('offer')->middleware('throttle:sensitive')->name('offers.accept');
Route::post('offers/{offer}/reject', [OfferController::class, 'reject'])->whereNumber('offer')->middleware('throttle:sensitive')->name('offers.reject');
Route::post('surplus/{surplusLot}/donations/accept', [DonationController::class, 'accept'])->whereNumber('surplusLot')->middleware('throttle:sensitive')->name('donations.accept');
Route::get('trades', [TradeController::class, 'index'])->name('trades.index');
Route::get('trades/{trade}', [TradeController::class, 'show'])->whereNumber('trade')->name('trades.show');
Route::post('surplus/{surplusLot}/buy-now', [TradeController::class, 'buyNow'])->whereNumber('surplusLot')->middleware('throttle:sensitive')->name('trades.buy_now');
Route::post('trades/{trade}/cancel', [TradeCancellationController::class, 'cancel'])->whereNumber('trade')->middleware('throttle:sensitive')->name('trades.cancel');

Route::post('trades/{trade}/shipping', [ShippingController::class, 'store'])->whereNumber('trade')->middleware('throttle:sensitive')->name('shipping.store');
Route::get('trades/{trade}/shipping', [ShippingController::class, 'show'])->whereNumber('trade')->name('shipping.show');
Route::get('shipping-requests', [ShippingController::class, 'index'])->name('shipping.index');
Route::post('shipping-requests/{shippingRequest}/offers', [ShippingController::class, 'offer'])->whereNumber('shippingRequest')->middleware('throttle:sensitive')->name('shipping.offers.store');
Route::patch('shipping-offers/{shippingOffer}', [ShippingController::class, 'updateOffer'])->whereNumber('shippingOffer')->middleware('throttle:sensitive')->name('shipping.offers.update');
Route::get('trades/{trade}/shipping-offers', [ShippingController::class, 'offers'])->whereNumber('trade')->name('shipping.offers.index');
Route::post('trades/{trade}/shipping-offers/{shippingOffer}/select', [ShippingController::class, 'select'])->whereNumber('trade')->whereNumber('shippingOffer')->middleware('throttle:sensitive')->name('shipping.offers.select');
Route::post('trades/{trade}/ngo-managed', [ShippingController::class, 'ngoManaged'])->whereNumber('trade')->middleware('throttle:sensitive')->name('shipping.ngo_managed');
Route::post('trades/{trade}/buyer-managed', [ShippingController::class, 'buyerManaged'])->whereNumber('trade')->middleware('throttle:sensitive')->name('shipping.buyer_managed');

Route::post('trades/{trade}/delivery/ready-for-pickup', [DeliveryController::class, 'readyForPickup'])->whereNumber('trade')->middleware('throttle:sensitive')->name('delivery.ready');
Route::post('trades/{trade}/delivery/ready-for-pickup/prepare', [DeliveryController::class, 'prepareReadyForPickup'])->whereNumber('trade')->middleware('throttle:sensitive')->name('delivery.ready.prepare');
Route::post('trades/{trade}/delivery/pickup', [DeliveryController::class, 'pickup'])->whereNumber('trade')->middleware('throttle:sensitive')->name('delivery.pickup');
Route::post('trades/{trade}/delivery/pickup/prepare', [DeliveryController::class, 'preparePickup'])->whereNumber('trade')->middleware('throttle:sensitive')->name('delivery.pickup.prepare');
Route::post('trades/{trade}/delivery/delivered', [DeliveryController::class, 'delivered'])->whereNumber('trade')->middleware('throttle:sensitive')->name('delivery.delivered');
Route::post('trades/{trade}/delivery/delivered/prepare', [DeliveryController::class, 'prepareDelivered'])->whereNumber('trade')->middleware('throttle:sensitive')->name('delivery.delivered.prepare');

Route::post('trades/{trade}/blockchain/prepare', [BlockchainTradeController::class, 'prepare'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.prepare');
Route::post('trades/{trade}/blockchain/initialize/confirm', [BlockchainTradeController::class, 'confirmInitialization'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.initialize.confirm');
Route::post('trades/{trade}/blockchain/funding/confirm', [BlockchainTradeController::class, 'confirmFunding'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.funding.confirm');
Route::post('trades/{trade}/blockchain/settlement/prepare', [BlockchainTradeController::class, 'prepareSettlement'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.settlement.prepare');
Route::post('trades/{trade}/blockchain/settlement/confirm', [BlockchainTradeController::class, 'confirmSettlement'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.settlement.confirm');
Route::post('trades/{trade}/blockchain/cancellation/prepare', [BlockchainTradeController::class, 'prepareCancellation'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.cancellation.prepare');
Route::post('trades/{trade}/blockchain/cancellation/confirm', [BlockchainTradeController::class, 'confirmCancellation'])->whereNumber('trade')->middleware('throttle:sensitive')->name('blockchain.cancellation.confirm');
Route::post('trades/{trade}/rescue-proof/prepare', [RescueProofController::class, 'prepare'])->whereNumber('trade')->middleware('throttle:sensitive')->name('rescue.prepare');
Route::post('trades/{trade}/rescue-proof/confirm', [RescueProofController::class, 'confirm'])->whereNumber('trade')->middleware('throttle:sensitive')->name('rescue.confirm');
Route::post('trades/{trade}/rescue-proof/producer/prepare', [RescueProofController::class, 'prepareProducer'])->whereNumber('trade')->middleware('throttle:sensitive')->name('rescue.producer.prepare');
Route::post('trades/{trade}/rescue-proof/producer/confirm', [RescueProofController::class, 'confirmProducer'])->whereNumber('trade')->middleware('throttle:sensitive')->name('rescue.producer.confirm');
Route::get('trades/{trade}/rescue-proof', [RescueProofController::class, 'show'])->whereNumber('trade')->name('rescue.show');

Route::get('trades/{trade}/blockchain', [BlockchainTradeController::class, 'show'])->whereNumber('trade')->name('blockchain.show');

Route::get('blockchain/protocol', [ProtocolController::class, 'show'])->name('blockchain.protocol.show');
Route::post('trades/{trade}/ratings', [RatingController::class, 'store'])->whereNumber('trade')->middleware(['throttle:sensitive', 'permission:rating.create'])->name('ratings.store');
Route::get('users/{user}/ratings', [RatingController::class, 'index'])->whereNumber('user')->name('ratings.index');
Route::get('users/{user}/reputation', [RatingController::class, 'reputation'])->whereNumber('user')->name('ratings.reputation');

Route::get('dashboard/producer', [DashboardController::class, 'producer'])->name('dashboard.producer');
Route::get('dashboard/buyer', [DashboardController::class, 'buyer'])->name('dashboard.buyer');
Route::get('dashboard/carrier', [DashboardController::class, 'carrier'])->name('dashboard.carrier');
Route::get('dashboard/ngo', [DashboardController::class, 'ngo'])->name('dashboard.ngo');
