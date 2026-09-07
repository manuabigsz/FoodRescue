<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->timestampTz('donation_accepted_at')->nullable()->index()->after('is_donation');
        });

        Schema::create('rescue_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('program_id', 64);
            $table->string('proof_pda', 64)->unique();
            $table->string('signature', 128)->unique();
            $table->unsignedBigInteger('slot')->nullable();
            $table->char('metadata_hash', 64);
            $table->timestampTz('confirmed_at');
            $table->json('metadata');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rescue_proofs');
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropColumn('donation_accepted_at');
        });
    }
};
