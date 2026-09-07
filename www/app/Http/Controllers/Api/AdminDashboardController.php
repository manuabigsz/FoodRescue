<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->admin()]);
    }

    public function impact(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->impact()]);
    }
}
