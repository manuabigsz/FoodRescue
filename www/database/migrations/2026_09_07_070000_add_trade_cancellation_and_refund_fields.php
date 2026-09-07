<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->foreignId('cancelled_by_id')->nullable()->after('buyer_id')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by_id');
            $table->timestampTz('cancelled_at')->nullable()->after('completed_at');
        });

        Schema::table('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('settled_at');
            $table->timestampTz('refunded_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('blockchain_trade_accounts', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'refunded_at']);
        });

        Schema::table('trades', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancellation_reason', 'cancelled_at']);
        });
    }
};
