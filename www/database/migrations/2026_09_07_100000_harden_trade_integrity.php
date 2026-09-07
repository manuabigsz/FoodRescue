<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->jsonb('blockchain_preparation')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX trades_one_active_surplus ON trades (surplus_lot_id) WHERE status NOT IN ('expired', 'cancelled')");
        Schema::table('shipping_requests', function (Blueprint $table): void {
            $table->foreign('selected_shipping_offer_id')->references('id')->on('shipping_offers')->nullOnDelete();
        });
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_value_check CHECK (rating BETWEEN 1 AND 5 AND reviewer_id <> target_user_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ratings DROP CONSTRAINT ratings_value_check');
        Schema::table('shipping_requests', function (Blueprint $table): void {
            $table->dropForeign(['selected_shipping_offer_id']);
        });
        DB::statement('DROP INDEX trades_one_active_surplus');
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropColumn('blockchain_preparation');
        });
    }
};
