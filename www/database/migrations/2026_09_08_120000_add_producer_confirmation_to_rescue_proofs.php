<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Proof of Rescue passou a ser atestado em duas transações: a NGO abre e o
 * produtor confirma, cada um assinando na própria carteira. A linha nasce com a
 * confirmação do produtor pendente e só fecha quando ela chega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rescue_proofs', function (Blueprint $table): void {
            $table->string('producer_signature', 128)->nullable()->unique()->after('signature');
            $table->timestampTz('producer_confirmed_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('rescue_proofs', function (Blueprint $table): void {
            $table->dropColumn(['producer_signature', 'producer_confirmed_at']);
        });
    }
};
