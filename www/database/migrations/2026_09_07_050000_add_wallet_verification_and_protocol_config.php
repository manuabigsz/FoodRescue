<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('solana_wallet_verified_at')->nullable()->after('solana_wallet_address');
        });

        Schema::create('wallet_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wallet_address', 44)->index();
            $table->string('purpose', 24);
            $table->uuid('nonce')->unique();
            $table->text('message');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->string('protocol_config_pda', 44)->nullable()->after('mint')->index();
        });

        Schema::create('blockchain_protocol_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('cluster', 32);
            $table->string('program_id', 44);
            $table->string('authority_wallet', 44);
            $table->string('treasury_wallet', 44);
            $table->string('mint', 44);
            $table->string('config_pda', 44)->unique();
            $table->unsignedTinyInteger('version')->default(1);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['cluster', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_protocol_configs');
        Schema::table('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->dropColumn('protocol_config_pda');
        });
        Schema::dropIfExists('wallet_challenges');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('solana_wallet_verified_at');
        });
    }
};
