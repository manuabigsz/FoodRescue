<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('surplus_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 18, 6);
            $table->string('status', 24)->default('pending')->index();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->index(['surplus_lot_id', 'buyer_id', 'status']);
        });

        Schema::create('trades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('surplus_lot_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('producer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_offer_id')->nullable()->unique()->constrained('offers')->nullOnDelete();
            $table->decimal('product_amount', 18, 6);
            $table->decimal('shipping_amount', 18, 6)->default(0);
            $table->decimal('protocol_fee', 18, 6)->default(0);
            $table->string('status', 32)->default('reserved')->index();
            $table->boolean('is_donation')->default(false)->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
        Schema::dropIfExists('offers');
    }
};
