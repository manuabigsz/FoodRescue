<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreAgriculturalProductRequest;
use App\Http\Requests\Api\Admin\StoreQualityGradeRequest;
use App\Http\Requests\Api\Admin\UpdateAgriculturalProductRequest;
use App\Http\Requests\Api\Admin\UpdateQualityGradeRequest;
use App\Http\Resources\AgriculturalProductResource;
use App\Http\Resources\QualityGradeResource;
use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminCatalogController extends Controller
{
    public function products(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AgriculturalProduct::class);

        return AgriculturalProductResource::collection(AgriculturalProduct::orderBy('name')->paginate(100));
    }

    public function storeProduct(StoreAgriculturalProductRequest $request): JsonResponse
    {
        Gate::authorize('create', AgriculturalProduct::class);
        $data = $request->validated();
        $product = AgriculturalProduct::create($data + ['slug' => Str::slug($data['name'])]);

        return (new AgriculturalProductResource($product))->response()->setStatusCode(201);
    }

    public function updateProduct(UpdateAgriculturalProductRequest $request, AgriculturalProduct $product): AgriculturalProductResource
    {
        Gate::authorize('update', $product);
        $data = $request->validated();
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        $product->update($data);

        return new AgriculturalProductResource($product);
    }

    public function qualityGrades(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', QualityGrade::class);

        return QualityGradeResource::collection(QualityGrade::orderBy('sort_order')->orderBy('name')->paginate(100));
    }

    public function storeQualityGrade(StoreQualityGradeRequest $request): JsonResponse
    {
        Gate::authorize('create', QualityGrade::class);

        return (new QualityGradeResource(QualityGrade::create($request->validated())))->response()->setStatusCode(201);
    }

    public function updateQualityGrade(UpdateQualityGradeRequest $request, QualityGrade $qualityGrade): QualityGradeResource
    {
        Gate::authorize('update', $qualityGrade);
        $qualityGrade->update($request->validated());

        return new QualityGradeResource($qualityGrade);
    }
}
