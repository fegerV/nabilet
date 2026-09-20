<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Resources\AuthResource;
use App\Modules\Core\Users\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    /**
     * Register a new customer user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->registerCustomer(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('first_name'),
            $request->validated('last_name'),
            $request->validated('phone')
        );

        $token = $user->createToken('customer-token')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new AuthResource($user),
                'token' => $token,
                'token_type' => 'Bearer',
            ]
        ], 201);
    }

    /**
     * Login by email and password
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->userService->authenticate(
            $request->validated('email'),
            $request->validated('password')
        );

        if (!$user) {
            return response()->json([
                'error' => 'Invalid credentials'
            ], 401);
        }

        $token = $user->createToken('customer-token')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new AuthResource($user),
                'token' => $token,
                'token_type' => 'Bearer',
            ]
        ]);
    }

    /**
     * Logout current session
     */
    public function logout(): Response
    {
        auth()->user()?->currentAccessToken()->delete();

        return response(null, 204);
    }

    /**
     * Request password reset
     */
    public function forgotPassword(\Illuminate\Http\Request $request): Response
    {
        $request->validate([
            'email' => ['required', 'email']
        ]);

        $this->userService->sendPasswordResetLink($request->email);

        return response(null, 204);
    }

    /**
     * Reset password with token
     */
    public function resetPassword(\Illuminate\Http\Request $request): Response
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:12', 'confirmed'],
        ]);

        $this->userService->resetPassword(
            $request->email,
            $request->token,
            $request->password
        );

        return response(null, 204);
    }

    /**
     * Verify email address
     */
    public function verifyEmail(\Illuminate\Http\Request $request): Response
    {
        $request->validate([
            'token' => ['required']
        ]);

        $this->userService->verifyEmail(auth()->user(), $request->token);

        return response(null, 204);
    }
}
