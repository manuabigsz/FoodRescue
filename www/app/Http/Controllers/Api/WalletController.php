<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifyWalletRequest;
use App\Http\Requests\Api\WalletChallengeRequest;
use App\Http\Resources\UserResource;
use App\Services\WalletVerificationService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function registrationChallenge(WalletChallengeRequest $request, WalletVerificationService $wallets): JsonResponse
    {
        return response()->json(['data' => $wallets->createRegistrationChallenge($request->validated('wallet_address'))], 201);
    }

    public function challenge(WalletChallengeRequest $request, WalletVerificationService $wallets): JsonResponse
    {
        return response()->json(['data' => $wallets->createUserChallenge($request->user(), $request->validated('wallet_address'))], 201);
    }

    public function verify(VerifyWalletRequest $request, WalletVerificationService $wallets): UserResource
    {
        return new UserResource($wallets->verifyForUser(
            $request->user(),
            (int) $request->validated('challenge_id'),
            $request->validated('signature'),
        ));
    }
}
