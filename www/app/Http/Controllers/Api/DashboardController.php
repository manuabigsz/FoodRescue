<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function producer(Request $request): JsonResponse
    {
        return $this->forRole($request->user(), UserRole::Producer, fn (User $user) => $this->dashboard->producer($user));
    }

    public function buyer(Request $request): JsonResponse
    {
        return $this->forRole($request->user(), UserRole::Buyer, fn (User $user) => $this->dashboard->buyer($user));
    }

    public function carrier(Request $request): JsonResponse
    {
        return $this->forRole($request->user(), UserRole::Carrier, fn (User $user) => $this->dashboard->carrier($user));
    }

    public function ngo(Request $request): JsonResponse
    {
        return $this->forRole($request->user(), UserRole::Ngo, fn (User $user) => $this->dashboard->ngo($user));
    }

    /** @param callable(User): array<string, mixed> $callback */
    private function forRole(User $user, UserRole $role, callable $callback): JsonResponse
    {
        abort_unless($user->hasRole($role->value), Response::HTTP_FORBIDDEN, 'Este dashboard não está disponível para o seu papel.');

        return response()->json(['data' => $callback($user)]);
    }
}
