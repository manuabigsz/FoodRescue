<?php

namespace App\Http\Controllers\Api;

use App\Enums\TradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Trade\ListTradesRequest;
use App\Http\Resources\TradeResource;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\Services\Marketplace\SurplusMarketplace;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TradeController extends Controller
{
    /** @var array<int, string> */
    private const RELATIONS = [
        'surplusLot.agriculturalProduct',
        'surplusLot.qualityGrade',
        'shippingRequest.selectedOffer.carrier',
        'blockchainAccount',
        'rescueProof',
    ];

    public function index(ListTradesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Trade::class);
        $data = $request->validated();
        $query = Trade::query()->with(self::RELATIONS);

        $this->scopeToParticipant($query, $request->user());

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['is_donation'])) {
            $query->where('is_donation', $request->boolean('is_donation'));
        }
        if ($request->boolean('active')) {
            $query->whereNotIn('status', [TradeStatus::Completed->value, TradeStatus::Cancelled->value, TradeStatus::Expired->value]);
        }

        return TradeResource::collection(
            $query->latest('id')->paginate($data['per_page'] ?? 20)->withQueryString(),
        );
    }

    public function show(Trade $trade): TradeResource
    {
        Gate::authorize('view', $trade);

        return new TradeResource($trade->load(self::RELATIONS));
    }

    public function buyNow(SurplusLot $surplusLot, SurplusMarketplace $marketplace): TradeResource
    {
        $user = request()->user();
        Gate::authorize('create', [Offer::class, $surplusLot]);
        abort_unless($user->hasRole(UserRole::Buyer->value), 403);

        return new TradeResource($marketplace->buyNow($user, $surplusLot));
    }

    /**
     * Restringe a listagem às operações em que o usuário participa. O produtor e
     * o destinatário aparecem por coluna direta; a transportadora, apenas quando
     * teve a própria cotação selecionada. O administrador enxerga todas.
     */
    private function scopeToParticipant(Builder $query, User $user): void
    {
        if ($user->hasRole(UserRole::Admin->value)) {
            return;
        }

        $query->where(function (Builder $scope) use ($user): void {
            $scope->where('producer_id', $user->id)
                ->orWhere('buyer_id', $user->id)
                ->orWhereHas(
                    'shippingRequest.selectedOffer',
                    fn (Builder $offer) => $offer->where('carrier_id', $user->id),
                );
        });
    }
}
