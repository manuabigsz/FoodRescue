<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropUnique(['surplus_lot_id']);
            $table->index('surplus_lot_id');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropIndex(['surplus_lot_id']);
            $table->unique('surplus_lot_id');
        });
    }
};
