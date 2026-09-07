<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Rating\StoreRatingRequest;
use App\Http\Resources\RatingResource;
use App\Models\Rating;
use App\Models\Trade;
use App\Models\User;
use App\Services\Reputation\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RatingController extends Controller
{
    public function store(StoreRatingRequest $request, Trade $trade, RatingService $ratings): RatingResource
    {
        $data = $request->validated();
        $target = User::query()->findOrFail($data['target_user_id']);

        $rating = $ratings->create(
            $request->user(),
            $trade,
            $target,
            $data['rating'],
            $data['comment'] ?? null,
        );

        return new RatingResource($rating->load('reviewer.roles'));
    }

    public function index(User $user): AnonymousResourceCollection
    {
        $ratings = Rating::query()
            ->where('target_user_id', $user->id)
            ->with('reviewer.roles')
            ->latest('id')
            ->paginate(20);

        return RatingResource::collection($ratings);
    }

    public function reputation(User $user): JsonResponse
    {
        $aggregate = Rating::query()
            ->where('target_user_id', $user->id)
            ->selectRaw('COUNT(*) AS rating_count, AVG(rating) AS rating_average')
            ->first();

        $count = (int) ($aggregate?->rating_count ?? 0);
        $average = $count > 0 ? round((float) $aggregate->rating_average, 2) : null;

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'average' => $average,
                'count' => $count,
            ],
        ]);
    }
}
