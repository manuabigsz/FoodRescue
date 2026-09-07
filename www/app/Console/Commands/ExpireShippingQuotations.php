<?php

namespace App\Console\Commands;

use App\Services\Logistics\TradeLogistics;
use Illuminate\Console\Command;

class ExpireShippingQuotations extends Command
{
    protected $signature = 'foodrescue:expire-shipping-quotations';

    protected $description = 'Finaliza cotações de frete vencidas usando transporte gerenciado pelo comprador.';

    public function handle(TradeLogistics $logistics): int
    {
        $this->info($logistics->expireQuotations().' cotação(ões) expirada(s).');

        return self::SUCCESS;
    }
}
