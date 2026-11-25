<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Modules\Identity\Application\Actions\LoginAction;
use App\Modules\Identity\Application\Actions\LogoutAction;
use App\Modules\Identity\Application\Actions\RefreshTokenAction;
use App\Modules\Identity\Application\Actions\RegisterAction;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RefreshRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\AuthenticationResource;
use App\Modules\Identity\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController
{
    public function login(LoginRequest $request, LoginAction $action): AuthenticationResource
    {
        return new AuthenticationResource($action->execute($request->toDto()));
    }

    public function register(RegisterRequest $request, RegisterAction $action): JsonResponse
    {
        return (new AuthenticationResource($action->execute($request->toDto())))->response()->setStatusCode(201);
    }

    public function refresh(RefreshRequest $request, RefreshTokenAction $action): AuthenticationResource
    {
        return new AuthenticationResource($action->execute($request->toDto()));
    }

    public function logout(Request $request, LogoutAction $action): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        $action->execute(
            (int) $user->id,
            $request->boolean('all_devices'),
            $token instanceof PersonalAccessToken ? (int) $token->id : null,
            $token instanceof PersonalAccessToken ? (string) $token->name : null,
            $request->hasSession(),
            $request->ip() ?? '',
        );

        return response()->json([
            'status' => 'success',
            'message' => __('identity.logged_out'),
        ]);
    }

    public function me(Request $request): ProfileResource
    {
        return new ProfileResource($request->user());
    }
}
