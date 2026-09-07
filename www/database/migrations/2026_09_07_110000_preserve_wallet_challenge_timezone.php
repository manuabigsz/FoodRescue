<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Challenges were explicitly written in UTC, even when APP_TIMEZONE differed.
        DB::statement("ALTER TABLE wallet_challenges ALTER COLUMN expires_at TYPE timestamp(0) with time zone USING expires_at AT TIME ZONE 'UTC'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE wallet_challenges ALTER COLUMN expires_at TYPE timestamp(0) without time zone USING expires_at AT TIME ZONE 'UTC'");
    }
};
