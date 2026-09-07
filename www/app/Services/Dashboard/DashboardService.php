<?php

namespace App\Services\Dashboard;

use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\Rating;
use App\Models\ShippingOffer;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    /** @return array<string, mixed> */
    public function producer(User $user): array
    {
        $completed = $this->completedTrades()->where('trades.producer_id', $user->id);
        $commercial = (clone $completed)->where('is_donation', false);
        $donations = (clone $completed)->where('is_donation', true);

        return [
            'role' => 'producer',
            'summary' => [
                'active_surplus' => SurplusLot::query()->where('producer_id', $user->id)->whereIn('status', [SurplusStatus::Open, SurplusStatus::Reserved])->count(),
                'commercial_operations' => (clone $commercial)->count(),
                'donation_operations' => (clone $donations)->count(),
                'recovered_revenue' => $this->decimalExpressionSum($commercial, 'product_amount - protocol_fee'),
                'ratings' => $this->reputation($user),
            ],
            'quantities' => [
                'sold' => $this->quantityByUnit((clone $commercial)),
                'donated' => $this->quantityByUnit((clone $donations)),
                'total_destined' => $this->quantityByUnit((clone $completed)),
            ],
            'trade_statuses' => $this->tradeStatusCounts(Trade::query()->where('trades.producer_id', $user->id)),
        ];
    }

    /** @return array<string, mixed> */
    public function buyer(User $user): array
    {
        $completed = $this->completedTrades()->where('buyer_id', $user->id)->where('is_donation', false);

        return [
            'role' => 'buyer',
            'summary' => [
                'completed_purchases' => (clone $completed)->count(),
                'product_spend' => $this->decimalSum((clone $completed), 'product_amount'),
                'shipping_spend' => $this->decimalSum((clone $completed), 'shipping_amount'),
                'total_spend' => $this->decimalExpressionSum((clone $completed), 'product_amount + shipping_amount'),
                'ratings' => $this->reputation($user),
            ],
            'quantities' => [
                'purchased' => $this->quantityByUnit((clone $completed)),
            ],
            'trade_statuses' => $this->tradeStatusCounts(Trade::query()->where('buyer_id', $user->id)->where('is_donation', false)),
        ];
    }

    /** @return array<string, mixed> */
    public function carrier(User $user): array
    {
        $completedTradeIds = ShippingOffer::query()
            ->join('shipping_requests', 'shipping_requests.selected_shipping_offer_id', '=', 'shipping_offers.id')
            ->join('trades', 'trades.id', '=', 'shipping_requests.trade_id')
            ->where('shipping_offers.carrier_id', $user->id)
            ->where('trades.status', TradeStatus::Completed->value)
            ->pluck('trades.id');

        $completed = Trade::query()->whereIn('trades.id', $completedTradeIds);
        $allSelectedTradeIds = ShippingOffer::query()
            ->join('shipping_requests', 'shipping_requests.selected_shipping_offer_id', '=', 'shipping_offers.id')
            ->where('shipping_offers.carrier_id', $user->id)
            ->pluck('shipping_requests.trade_id');

        return [
            'role' => 'carrier',
            'summary' => [
                'completed_deliveries' => (clone $completed)->count(),
                'freight_revenue' => $this->decimalSum((clone $completed), 'shipping_amount'),
                'ratings' => $this->reputation($user),
            ],
            'quantities' => [
                'transported' => $this->quantityByUnit((clone $completed)),
            ],
            'trade_statuses' => $this->tradeStatusCounts(Trade::query()->whereIn('trades.id', $allSelectedTradeIds)),
        ];
    }

    /** @return array<string, mixed> */
    public function ngo(User $user): array
    {
        $completed = $this->completedTrades()->where('buyer_id', $user->id)->where('is_donation', true);

        return [
            'role' => 'ngo',
            'summary' => [
                'completed_donations' => (clone $completed)->count(),
                'shipping_spend' => $this->decimalSum((clone $completed), 'shipping_amount'),
                'rescue_proofs' => (clone $completed)->whereHas('rescueProof', fn (Builder $query) => $query->whereNotNull('confirmed_at'))->count(),
                'ratings' => $this->reputation($user),
            ],
            'quantities' => [
                'rescued' => $this->quantityByUnit((clone $completed)),
            ],
            'trade_statuses' => $this->tradeStatusCounts(Trade::query()->where('buyer_id', $user->id)->where('is_donation', true)),
        ];
    }

    /** @return array<string, mixed> */
    public function admin(): array
    {
        $completed = $this->completedTrades();
        $commercial = (clone $completed)->where('is_donation', false);
        $donations = (clone $completed)->where('is_donation', true);

        return [
            'summary' => [
                'users' => [
                    'total' => User::query()->count(),
                    'producers' => User::role('producer')->count(),
                    'buyers' => User::role('buyer')->count(),
                    'carriers' => User::role('carrier')->count(),
                    'ngos' => User::role('ngo')->count(),
                ],
                'open_surplus' => SurplusLot::query()->where('status', SurplusStatus::Open)->count(),
                'commercial_operations' => (clone $commercial)->count(),
                'donation_operations' => (clone $donations)->count(),
                'producer_revenue_recovered' => $this->decimalExpressionSum((clone $commercial), 'product_amount - protocol_fee'),
                'protocol_fees' => $this->decimalSum((clone $commercial), 'protocol_fee'),
                'carrier_freight_paid' => $this->decimalSum((clone $completed), 'shipping_amount'),
            ],
            'trade_statuses' => $this->tradeStatusCounts(Trade::query()),
        ];
    }

    /** @return array<string, mixed> */
    public function impact(): array
    {
        $completed = $this->completedTrades();
        $commercial = (clone $completed)->where('is_donation', false);
        $donations = (clone $completed)->where('is_donation', true);

        return [
            'quantities' => [
                'total_destined' => $this->quantityByUnit((clone $completed)),
                'sold' => $this->quantityByUnit((clone $commercial)),
                'donated' => $this->quantityByUnit((clone $donations)),
            ],
            'financial' => [
                'producer_revenue_recovered' => $this->decimalExpressionSum((clone $commercial), 'product_amount - protocol_fee'),
                'protocol_fees' => $this->decimalSum((clone $commercial), 'protocol_fee'),
                'freight_paid' => $this->decimalSum((clone $completed), 'shipping_amount'),
            ],
            'operations' => [
                'commercial' => (clone $commercial)->count(),
                'donations' => (clone $donations)->count(),
                'total' => (clone $completed)->count(),
            ],
            'participants' => [
                'producers' => (clone $completed)->distinct('producer_id')->count('producer_id'),
                'commercial_buyers' => (clone $commercial)->distinct('buyer_id')->count('buyer_id'),
                'ngos' => (clone $donations)->distinct('buyer_id')->count('buyer_id'),
                'carriers' => ShippingOffer::query()
                    ->join('shipping_requests', 'shipping_requests.selected_shipping_offer_id', '=', 'shipping_offers.id')
                    ->join('trades', 'trades.id', '=', 'shipping_requests.trade_id')
                    ->where('trades.status', TradeStatus::Completed->value)
                    ->distinct('shipping_offers.carrier_id')
                    ->count('shipping_offers.carrier_id'),
            ],
        ];
    }

    private function completedTrades(): Builder
    {
        return Trade::query()->where('trades.status', TradeStatus::Completed);
    }

    /** @return array{average: float|null, count: int} */
    private function reputation(User $user): array
    {
        $query = Rating::query()->where('target_user_id', $user->id);
        $count = (clone $query)->count();

        return [
            'average' => $count > 0 ? round((float) (clone $query)->avg('rating'), 2) : null,
            'count' => $count,
        ];
    }

    /** @return array<string, string> */
    private function quantityByUnit(Builder $trades): array
    {
        return $trades
            ->join('surplus_lots', 'surplus_lots.id', '=', 'trades.surplus_lot_id')
            ->selectRaw('surplus_lots.unit, SUM(surplus_lots.quantity) as total_quantity')
            ->groupBy('surplus_lots.unit')
            ->orderBy('surplus_lots.unit')
            ->pluck('total_quantity', 'surplus_lots.unit')
            ->map(fn ($value): string => $this->normalizeDecimal($value, 3))
            ->all();
    }

    /** @return array<string, int> */
    private function tradeStatusCounts(Builder $query): array
    {
        return $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function decimalSum(Builder $query, string $column): string
    {
        return $this->normalizeDecimal($query->sum($column), 6);
    }

    private function decimalExpressionSum(Builder $query, string $expression): string
    {
        $aggregateQuery = clone $query;
        $value = $aggregateQuery->selectRaw("COALESCE(SUM($expression), 0) as aggregate")->value('aggregate');

        return $this->normalizeDecimal($value, 6);
    }

    private function normalizeDecimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) ($value ?? 0))->toScale($scale);
    }
}
