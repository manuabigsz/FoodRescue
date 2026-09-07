<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->timestampTz('ready_for_pickup_at')->nullable()->after('payment_expires_at');
            $table->timestampTz('picked_up_at')->nullable()->after('ready_for_pickup_at');
            $table->timestampTz('delivered_at')->nullable()->after('picked_up_at');
            $table->timestampTz('completed_at')->nullable()->after('delivered_at');
        });

        Schema::table('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->timestampTz('settled_at')->nullable()->after('funded_at');
        });
    }

    public function down(): void
    {
        Schema::table('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->dropColumn('settled_at');
        });

        Schema::table('trades', function (Blueprint $table): void {
            $table->dropColumn(['ready_for_pickup_at', 'picked_up_at', 'delivered_at', 'completed_at']);
        });
    }
};
