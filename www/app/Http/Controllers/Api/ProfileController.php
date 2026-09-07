<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Accounts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['roles', 'producerProfile', 'buyerProfile', 'carrierProfile', 'ngoProfile']));
    }

    public function update(UpdateProfileRequest $request, Accounts $accounts): UserResource
    {
        return new UserResource($accounts->updateProfile($request->user(), $request->validated()));
    }

    public function password(ChangePasswordRequest $request, Accounts $accounts): Response
    {
        $data = $request->validated();
        $accounts->changePassword($request->user(), $data['current_password'], $data['password']);

        return response()->noContent();
    }
}
