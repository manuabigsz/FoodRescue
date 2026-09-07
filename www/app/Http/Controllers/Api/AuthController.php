<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Accounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, Accounts $accounts): JsonResponse
    {
        return (new UserResource($accounts->register($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request, Accounts $accounts): JsonResponse
    {
        $data = $request->validated();
        $result = $accounts->login($data['email'], $data['password'], $data['device_name'] ?? 'api');

        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token']->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $result['token']->accessToken->expires_at->toISOString(),
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $request->user()->tokens()->delete();

        return response()->noContent();
    }
}
