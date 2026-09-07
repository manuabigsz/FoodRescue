<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateAdminRequest;
use App\Http\Requests\Api\ListUsersRequest;
use App\Http\Requests\Api\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Accounts;
use App\UserStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AdminUserController extends Controller
{
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $users = User::with(['roles', 'producerProfile', 'buyerProfile', 'carrierProfile', 'ngoProfile'])->orderBy('id');

        if (isset($data['role'])) {
            $users->role($data['role']);
        }

        if (isset($data['status'])) {
            $users->where('status', $data['status']);
        }

        return UserResource::collection($users->paginate($data['per_page'] ?? 20)->withQueryString());
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['roles', 'producerProfile', 'buyerProfile', 'carrierProfile', 'ngoProfile']));
    }

    public function store(CreateAdminRequest $request, Accounts $accounts): JsonResponse
    {
        return (new UserResource($accounts->createAdmin($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function status(UpdateUserStatusRequest $request, User $user, Accounts $accounts): UserResource
    {
        return new UserResource($accounts->updateStatus(
            $request->user(), $user, UserStatus::from($request->validated('status')),
        ));
    }
}
