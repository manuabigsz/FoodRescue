<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('solana_wallet_address', 44)->nullable()->unique()->after('email');
        });

        Schema::create('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cluster', 24);
            $table->string('program_id', 44);
            $table->string('mint', 44);
            $table->unsignedTinyInteger('token_decimals');
            $table->string('trade_pda', 44)->unique();
            $table->string('vault_token_account', 44)->unique();
            $table->timestampTz('initialized_at');
            $table->timestampTz('funded_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('blockchain_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('signature', 100)->unique();
            $table->unsignedBigInteger('slot');
            $table->string('status', 24);
            $table->timestampTz('confirmed_at');
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['trade_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_transactions');
        Schema::dropIfExists('blockchain_trade_accounts');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['solana_wallet_address']);
            $table->dropColumn('solana_wallet_address');
        });
    }
};
