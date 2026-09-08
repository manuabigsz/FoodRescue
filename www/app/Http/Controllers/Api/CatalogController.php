<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgriculturalProductResource;
use App\Http\Resources\QualityGradeResource;
use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catálogo de referência disponível para qualquer ator autenticado.
 *
 * A manutenção dos registros continua restrita ao administrador em AdminCatalogController.
 */
class CatalogController extends Controller
{
    public function products(): AnonymousResourceCollection
    {
        return AgriculturalProductResource::collection(
            AgriculturalProduct::query()->where('active', true)->orderBy('name')->get(),
        );
    }

    public function qualityGrades(): AnonymousResourceCollection
    {
        return QualityGradeResource::collection(
            QualityGrade::query()->where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
        );
    }
}
