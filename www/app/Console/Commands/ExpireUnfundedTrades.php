<?php

namespace App\Console\Commands;

use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use Illuminate\Console\Command;

class ExpireUnfundedTrades extends Command
{
    protected $signature = 'foodrescue:expire-unfunded-trades';

    protected $description = 'Expira trades não financiados dentro da janela de pagamento.';

    public function handle(TradeLogistics $logistics, SurplusMarketplace $marketplace): int
    {
        $this->info($marketplace->expireLotsAndOffers().' lote(s) expirado(s).');
        $this->info($logistics->expireUnfundedTrades().' trade(s) expirado(s).');

        return self::SUCCESS;
    }
}
