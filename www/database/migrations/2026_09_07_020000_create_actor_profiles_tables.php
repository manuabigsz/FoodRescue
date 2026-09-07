<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('producer_type', 20);
            $table->string('organization_name', 160)->nullable();
            $table->string('farm_name', 160)->nullable();
            $table->string('document_number', 40)->index();
            $table->string('phone', 30);
            $table->string('country', 80)->default('Brazil');
            $table->string('state', 100);
            $table->string('city', 120);
            $table->string('address_line', 255);
            $table->string('postal_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('buyer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('buyer_type', 20);
            $table->string('organization_name', 160)->nullable();
            $table->string('document_number', 40)->index();
            $table->string('phone', 30);
            $table->string('country', 80)->default('Brazil');
            $table->string('state', 100);
            $table->string('city', 120);
            $table->string('address_line', 255);
            $table->string('postal_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('carrier_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 160);
            $table->string('document_number', 40)->index();
            $table->string('phone', 30);
            $table->string('contact_name', 120);
            $table->string('country', 80)->default('Brazil');
            $table->string('state', 100);
            $table->string('city', 120);
            $table->string('address_line', 255);
            $table->string('postal_code', 20)->nullable();
            $table->json('service_regions');
            $table->json('vehicle_types')->nullable();
            $table->decimal('max_capacity_kg', 14, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('ngo_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('organization_name', 160);
            $table->string('registration_number', 60)->index();
            $table->string('phone', 30);
            $table->string('contact_name', 120);
            $table->string('country', 80)->default('Brazil');
            $table->string('state', 100);
            $table->string('city', 120);
            $table->string('address_line', 255);
            $table->string('postal_code', 20)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ngo_profiles');
        Schema::dropIfExists('carrier_profiles');
        Schema::dropIfExists('buyer_profiles');
        Schema::dropIfExists('producer_profiles');
    }
};
