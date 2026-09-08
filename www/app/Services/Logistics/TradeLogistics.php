<?php

namespace App\Services\Logistics;

use App\Enums\LogisticsMode;
use App\Enums\ShippingOfferStatus;
use App\Enums\ShippingRequestStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\PlatformSetting;
use App\Models\ShippingOffer;
use App\Models\ShippingRequest;
use App\Models\Trade;
use App\Models\User;
use App\Services\Blockchain\SolanaRpcClient;
use App\Support\Base58;
use App\UserRole;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TradeLogistics
{
    private const TRADE_STATE_SIZE = 244;

    public function __construct(private readonly SolanaRpcClient $rpc) {}

    /** @param array<string, mixed> $destination */
    public function createShippingRequest(User $buyer, Trade $trade, array $destination): ShippingRequest
    {
        return DB::transaction(function () use ($buyer, $trade, $destination): ShippingRequest {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $this->assertRecipient($buyer, $lockedTrade);
            abort_unless($lockedTrade->status === TradeStatus::Reserved, 409, 'O trade não está aguardando definição de logística.');
            abort_if($lockedTrade->shippingRequest()->exists(), 409, 'A solicitação de transporte já foi criada.');

            $lot = $lockedTrade->surplusLot()->firstOrFail();
            abort_unless(in_array(LogisticsMode::ThirdPartyCarrier->value, $lot->accepted_logistics_modes ?? [], true), 409, 'O lote não aceita transportadora terceirizada.');

            $request = ShippingRequest::create([
                'trade_id' => $lockedTrade->id,
                'origin_address' => $lot->origin_address,
                'origin_city' => $lot->origin_city,
                'origin_state' => $lot->origin_state,
                'origin_country' => $lot->origin_country,
                'destination_address' => $destination['destination_address'],
                'destination_city' => $destination['destination_city'],
                'destination_state' => $destination['destination_state'],
                'destination_country' => strtoupper($destination['destination_country']),
                'quantity' => $lot->quantity,
                'unit' => $lot->unit,
                'quotation_expires_at' => now()->addMinutes(PlatformSetting::integer(PlatformSetting::SHIPPING_QUOTATION_TIMEOUT, 240)),
                'status' => ShippingRequestStatus::Quoting,
            ]);

            $lockedTrade->update(['status' => TradeStatus::ShippingQuotation]);

            return $request->load(['trade', 'offers.carrier.roles']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createOffer(User $carrier, ShippingRequest $shippingRequest, array $data): ShippingOffer
    {
        return DB::transaction(function () use ($carrier, $shippingRequest, $data): ShippingOffer {
            $request = ShippingRequest::whereKey($shippingRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertQuoting($request);
            $request->offers()->where('status', ShippingOfferStatus::Pending->value)
                ->where('expires_at', '<=', now())->update(['status' => ShippingOfferStatus::Expired->value]);
            abort_if($request->offers()->where('carrier_id', $carrier->id)->where('status', ShippingOfferStatus::Pending->value)->exists(), 409, 'A transportadora já possui uma proposta pendente.');

            $expiresAt = $data['expires_at'] ?? $request->quotation_expires_at;
            abort_if(Carbon::parse($expiresAt)->greaterThan($request->quotation_expires_at), 422, 'A validade da proposta não pode ultrapassar o prazo da cotação.');

            return $request->offers()->create([
                'carrier_id' => $carrier->id,
                'amount' => $data['amount'],
                'pickup_at' => $data['pickup_at'],
                'estimated_delivery_at' => $data['estimated_delivery_at'],
                'expires_at' => $expiresAt,
                'status' => ShippingOfferStatus::Pending,
            ])->load('carrier.roles');
        }, 3);
    }

    /**
     * Revisa a própria cotação enquanto ela ainda está pendente e a janela de
     * cotação aberta. Depois de selecionada pelo destinatário, os termos viram
     * base do pagamento e não podem mais mudar.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateOffer(User $carrier, ShippingOffer $shippingOffer, array $data): ShippingOffer
    {
        return DB::transaction(function () use ($carrier, $shippingOffer, $data): ShippingOffer {
            $offer = ShippingOffer::whereKey($shippingOffer->id)->lockForUpdate()->firstOrFail();
            abort_unless($offer->carrier_id === $carrier->id, 403, 'Esta proposta pertence a outra transportadora.');

            $request = ShippingRequest::whereKey($offer->shipping_request_id)->lockForUpdate()->firstOrFail();
            $this->assertQuoting($request);
            abort_unless($offer->status === ShippingOfferStatus::Pending, 409, 'Só propostas pendentes podem ser alteradas.');
            abort_if($request->selected_shipping_offer_id !== null, 409, 'A cotação já foi decidida pelo destinatário.');

            $expiresAt = $data['expires_at'] ?? $request->quotation_expires_at;
            abort_if(Carbon::parse($expiresAt)->greaterThan($request->quotation_expires_at), 422, 'A validade da proposta não pode ultrapassar o prazo da cotação.');

            $offer->update([
                'amount' => $data['amount'],
                'pickup_at' => $data['pickup_at'],
                'estimated_delivery_at' => $data['estimated_delivery_at'],
                'expires_at' => $expiresAt,
            ]);

            return $offer->load('carrier.roles');
        }, 3);
    }

    public function selectOffer(User $buyer, Trade $trade, ShippingOffer $shippingOffer): Trade
    {
        return DB::transaction(function () use ($buyer, $trade, $shippingOffer): Trade {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $this->assertRecipient($buyer, $lockedTrade);
            abort_unless($lockedTrade->status === TradeStatus::ShippingQuotation, 409, 'O trade não está em cotação de transporte.');
            $request = ShippingRequest::where('trade_id', $lockedTrade->id)->lockForUpdate()->firstOrFail();
            $this->assertQuoting($request);
            $offer = ShippingOffer::whereKey($shippingOffer->id)->lockForUpdate()->firstOrFail();
            abort_unless($offer->shipping_request_id === $request->id, 404);
            abort_unless($offer->status === ShippingOfferStatus::Pending, 409, 'A proposta de frete não está disponível.');
            abort_if($offer->expires_at?->isPast(), 409, 'A proposta de frete expirou.');

            $offer->update(['status' => ShippingOfferStatus::Selected]);
            $request->offers()->whereKeyNot($offer->id)->where('status', ShippingOfferStatus::Pending->value)->update(['status' => ShippingOfferStatus::Rejected->value]);
            $request->update(['status' => ShippingRequestStatus::CarrierSelected, 'selected_shipping_offer_id' => $offer->id]);
            $this->startPaymentWindow($lockedTrade, (string) $offer->amount);

            return $lockedTrade->fresh()->load(['shippingRequest.selectedOffer.carrier.roles']);
        }, 3);
    }

    public function recipientManaged(User $buyer, Trade $trade, array $destination): Trade
    {
        return DB::transaction(function () use ($buyer, $trade, $destination): Trade {
            $lockedTrade = Trade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            $this->assertRecipient($buyer, $lockedTrade);
            abort_unless(in_array($lockedTrade->status, [TradeStatus::Reserved, TradeStatus::ShippingQuotation], true), 409, 'A logística deste trade já foi definida.');

            $lot = $lockedTrade->surplusLot()->firstOrFail();
            $modes = $lot->accepted_logistics_modes ?? [];
            abort_unless(in_array(LogisticsMode::BuyerPickup->value, $modes, true), 409, 'O lote não permite transporte gerenciado pelo destinatário.');

            $request = $lockedTrade->shippingRequest()->lockForUpdate()->first();
            if ($request) {
                $request->offers()->where('status', ShippingOfferStatus::Pending->value)->update(['status' => ShippingOfferStatus::Rejected->value]);
                $request->update([
                    'destination_address' => $destination['destination_address'],
                    'destination_city' => $destination['destination_city'],
                    'destination_state' => $destination['destination_state'],
                    'destination_country' => strtoupper($destination['destination_country']),
                    'status' => ShippingRequestStatus::BuyerManaged,
                    'selected_shipping_offer_id' => null,
                ]);
            } else {
                ShippingRequest::create([
                    'trade_id' => $lockedTrade->id,
                    'origin_address' => $lot->origin_address,
                    'origin_city' => $lot->origin_city,
                    'origin_state' => $lot->origin_state,
                    'origin_country' => $lot->origin_country,
                    'destination_address' => $destination['destination_address'],
                    'destination_city' => $destination['destination_city'],
                    'destination_state' => $destination['destination_state'],
                    'destination_country' => strtoupper($destination['destination_country']),
                    'quantity' => $lot->quantity,
                    'unit' => $lot->unit,
                    'quotation_expires_at' => now(),
                    'status' => ShippingRequestStatus::BuyerManaged,
                ]);
            }
            $this->startPaymentWindow($lockedTrade, '0');

            return $lockedTrade->fresh()->load('shippingRequest');
        }, 3);
    }

    public function buyerManaged(User $buyer, Trade $trade, array $destination): Trade
    {
        abort_unless($buyer->hasRole(UserRole::Buyer->value), 403);

        return $this->recipientManaged($buyer, $trade, $destination);
    }

    public function expireQuotations(): int
    {
        $count = 0;
        ShippingRequest::query()->where('status', ShippingRequestStatus::Quoting->value)->where('quotation_expires_at', '<=', now())->pluck('id')->each(function (int $id) use (&$count): void {
            DB::transaction(function () use ($id, &$count): void {
                $tradeId = ShippingRequest::whereKey($id)->value('trade_id');
                if ($tradeId === null) {
                    return;
                }
                $trade = Trade::whereKey($tradeId)->lockForUpdate()->firstOrFail();
                $request = ShippingRequest::whereKey($id)->lockForUpdate()->first();
                if (! $request || $request->status !== ShippingRequestStatus::Quoting
                    || $trade->status !== TradeStatus::ShippingQuotation
                    || $request->quotation_expires_at->isFuture()) {
                    return;
                }
                $request->offers()->where('status', ShippingOfferStatus::Pending->value)->update(['status' => ShippingOfferStatus::Expired->value]);
                $request->update(['status' => ShippingRequestStatus::BuyerManaged, 'selected_shipping_offer_id' => null]);
                $this->startPaymentWindow($trade, '0');
                $count++;
            }, 3);
        });

        return $count;
    }

    public function expireUnfundedTrades(): int
    {
        $count = 0;
        Trade::query()->where('status', TradeStatus::WaitingPayment->value)->whereNotNull('payment_expires_at')->where('payment_expires_at', '<=', now())->pluck('id')->each(function (int $id) use (&$count): void {
            $result = DB::transaction(function () use ($id): ?array {
                $trade = Trade::whereKey($id)->lockForUpdate()->first();
                if (! $trade || $trade->status !== TradeStatus::WaitingPayment || ! $trade->payment_expires_at?->lessThanOrEqualTo(now())) {
                    return null;
                }
                if ($trade->blockchainAccount()->exists()) {
                    return ['deferred' => true];
                }
                if ($trade->blockchain_preparation !== null) {
                    return ['prepared' => $trade->blockchain_preparation];
                }

                $this->expireTradeLocked($trade);

                return ['expired' => true];
            }, 3);

            if (($result['prepared'] ?? null) !== null && $this->reconcilePreparedTrade($id, $result['prepared'])) {
                $count++;
            } elseif (($result['expired'] ?? false) === true) {
                $count++;
            }
        });

        return $count;
    }

    /** @param array<string, mixed> $preparation */
    private function reconcilePreparedTrade(int $tradeId, array $preparation): bool
    {
        $trade = Trade::find($tradeId);
        if (! $trade || $trade->status !== TradeStatus::WaitingPayment || ! $trade->payment_expires_at
            || ! is_string($preparation['program_id'] ?? null)
            || $trade->blockchainAccount()->exists()) {
            return false;
        }

        try {
            $accounts = $this->rpc->programAccounts($preparation['program_id'], [
                ['dataSize' => self::TRADE_STATE_SIZE],
                ['memcmp' => [
                    'offset' => 3,
                    'bytes' => Base58::encode(pack('P', $trade->id)),
                ]],
            ]);
            $hasTradeState = collect($accounts)->contains(function (array $entry): bool {
                $encoded = data_get($entry, 'account.data.0');
                $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (! is_string($raw) || strlen($raw) !== self::TRADE_STATE_SIZE) {
                    throw new RuntimeException('A RPC retornou um TradeState inválido durante a reconciliação.');
                }

                return true;
            });
        } catch (RuntimeException) {
            return false;
        }

        if ($hasTradeState) {
            return false;
        }

        return (bool) DB::transaction(function () use ($tradeId, $preparation): bool {
            $locked = Trade::whereKey($tradeId)->lockForUpdate()->first();
            if (! $locked || $locked->status !== TradeStatus::WaitingPayment || ! $locked->payment_expires_at
                || $locked->blockchainAccount()->exists()
                || serialize($locked->blockchain_preparation) !== serialize($preparation)) {
                return false;
            }

            $this->expireTradeLocked($locked);

            return true;
        }, 3);
    }

    private function expireTradeLocked(Trade $trade): void
    {
        $trade->update(['status' => TradeStatus::Expired]);
        $trade->surplusLot()->update([
            'status' => $trade->surplusLot->available_until->isPast() ? SurplusStatus::Expired : SurplusStatus::Open,
            'reserved_at' => null,
        ]);
        $request = $trade->shippingRequest()->lockForUpdate()->first();
        if ($request) {
            $request->offers()->whereIn('status', [ShippingOfferStatus::Pending->value, ShippingOfferStatus::Selected->value])->update(['status' => ShippingOfferStatus::Expired->value]);
            $request->update(['status' => ShippingRequestStatus::Expired]);
        }
    }

    private function startPaymentWindow(Trade $trade, string $shippingAmount): void
    {
        if ($trade->is_donation && BigDecimal::of($shippingAmount)->isZero()) {
            $trade->update([
                'shipping_amount' => 0,
                'protocol_fee' => 0,
                'status' => TradeStatus::Funded,
                'payment_expires_at' => null,
            ]);

            return;
        }

        $trade->update([
            'shipping_amount' => $shippingAmount,
            'status' => TradeStatus::WaitingPayment,
            'payment_expires_at' => now()->addMinutes(PlatformSetting::integer(PlatformSetting::PAYMENT_TIMEOUT, 15)),
        ]);
    }

    private function assertRecipient(User $actor, Trade $trade): void
    {
        abort_unless($trade->buyer_id === $actor->id, 403);
        if ($trade->is_donation) {
            abort_unless($actor->hasRole(UserRole::Ngo->value), 403);

            return;
        }
        abort_unless($actor->hasRole(UserRole::Buyer->value), 403);
    }

    private function assertQuoting(ShippingRequest $request): void
    {
        abort_unless($request->status === ShippingRequestStatus::Quoting, 409, 'A solicitação não está recebendo cotações.');
        abort_if($request->quotation_expires_at->isPast(), 409, 'O prazo de cotação expirou.');
    }
}
