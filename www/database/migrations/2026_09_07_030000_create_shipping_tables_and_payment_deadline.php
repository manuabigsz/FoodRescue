<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestampsTz();
        });

        DB::table('platform_settings')->insert([
            ['key' => 'shipping_quotation_timeout_minutes', 'value' => '240', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payment_timeout_minutes', 'value' => '15', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('trades', function (Blueprint $table): void {
            $table->timestampTz('payment_expires_at')->nullable()->index()->after('is_donation');
        });

        Schema::create('shipping_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('origin_address');
            $table->string('origin_city');
            $table->string('origin_state', 80);
            $table->string('origin_country', 2);
            $table->string('destination_address');
            $table->string('destination_city');
            $table->string('destination_state', 80);
            $table->string('destination_country', 2)->default('BR');
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 16);
            $table->timestampTz('quotation_expires_at')->index();
            $table->string('status', 32)->default('quoting')->index();
            $table->unsignedBigInteger('selected_shipping_offer_id')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'quotation_expires_at']);
        });

        Schema::create('shipping_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 18, 6);
            $table->timestampTz('pickup_at');
            $table->timestampTz('estimated_delivery_at');
            $table->timestampTz('expires_at')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->timestampsTz();
            $table->index(['shipping_request_id', 'carrier_id', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_offers');
        Schema::dropIfExists('shipping_requests');
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropColumn('payment_expires_at');
        });
        Schema::dropIfExists('platform_settings');
    }
};
