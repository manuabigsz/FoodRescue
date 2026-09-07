<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surplus_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('agricultural_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('quality_grade_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 16);
            $table->string('origin_address');
            $table->string('origin_city');
            $table->string('origin_state', 80);
            $table->string('origin_country', 2)->default('BR');
            $table->date('harvest_date');
            $table->timestampTz('available_until')->index();
            $table->decimal('asking_price', 18, 6);
            $table->decimal('minimum_price', 18, 6)->nullable();
            $table->boolean('donation_eligible')->default(false)->index();
            $table->jsonb('accepted_logistics_modes');
            $table->string('status', 24)->default('open')->index();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampsTz();
            $table->index(['agricultural_product_id', 'status']);
            $table->index(['origin_state', 'origin_city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surplus_lots');
    }
};
