<?php

namespace App\Http\Controllers\Api;

use App\Enums\SurplusStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Surplus\ListSurplusRequest;
use App\Http\Requests\Api\Surplus\StoreSurplusRequest;
use App\Http\Requests\Api\Surplus\UpdateSurplusRequest;
use App\Http\Resources\SurplusLotResource;
use App\Models\SurplusLot;
use App\Services\Marketplace\SurplusMarketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SurplusLotController extends Controller
{
    public function index(ListSurplusRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $query = SurplusLot::query()
            ->with(['producer.roles', 'agriculturalProduct', 'qualityGrade'])
            ->where('available_until', '>', now());

        if (! isset($data['status'])) {
            $query->where('status', SurplusStatus::Open->value);
        } else {
            $query->where('status', $data['status']);
        }
        if (isset($data['product_id'])) {
            $query->where('agricultural_product_id', $data['product_id']);
        }
        if (isset($data['quality_grade_id'])) {
            $query->where('quality_grade_id', $data['quality_grade_id']);
        }
        if (isset($data['city'])) {
            $query->whereRaw('LOWER(origin_city) = ?', [mb_strtolower($data['city'])]);
        }
        if (isset($data['state'])) {
            $query->whereRaw('LOWER(origin_state) = ?', [mb_strtolower($data['state'])]);
        }
        if (isset($data['min_price'])) {
            $query->where('asking_price', '>=', $data['min_price']);
        }
        if (isset($data['max_price'])) {
            $query->where('asking_price', '<=', $data['max_price']);
        }
        if (isset($data['donation_eligible'])) {
            $query->where('donation_eligible', $data['donation_eligible']);
        }

        match ($data['sort'] ?? 'urgency') {
            'price_asc' => $query->orderBy('asking_price'),
            'price_desc' => $query->orderByDesc('asking_price'),
            'newest' => $query->latest('id'),
            default => $query->orderBy('available_until'),
        };

        return SurplusLotResource::collection($query->paginate($data['per_page'] ?? 20)->withQueryString());
    }

    public function show(SurplusLot $surplusLot): SurplusLotResource
    {
        return new SurplusLotResource($surplusLot->load(['producer.roles', 'agriculturalProduct', 'qualityGrade']));
    }

    public function store(StoreSurplusRequest $request, SurplusMarketplace $marketplace): JsonResponse
    {
        Gate::authorize('create', SurplusLot::class);

        return (new SurplusLotResource($marketplace->createLot($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateSurplusRequest $request, SurplusLot $surplusLot, SurplusMarketplace $marketplace): SurplusLotResource
    {
        Gate::authorize('update', $surplusLot);

        return new SurplusLotResource($marketplace->updateLot($surplusLot, $request->validated()));
    }

    public function cancel(SurplusLot $surplusLot, SurplusMarketplace $marketplace): SurplusLotResource
    {
        Gate::authorize('cancel', $surplusLot);

        return new SurplusLotResource($marketplace->cancelLot($surplusLot));
    }
}
