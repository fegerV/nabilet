<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Modules\Auth\Http\Requests\RegisterRequest;
use Nabilet\Modules\Auth\Http\Requests\LoginRequest;
use Nabilet\Modules\Auth\Http\Resources\AuthResource;
use Nabilet\Modules\Auth\Services\ClientContext;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly SessionIssuer $sessions,
    ) {}

    /**
     * Register a new customer user.
     *
     * `createUser()` is the only account-creation entry point `UserService`
     * actually has — the earlier `registerCustomer()` call was to a method that
     * never existed, which made every `POST /api/v1/auth/register` a fatal 500.
     *
     * There is no `try`/`catch` around it: a duplicate email is a `ValidationError`
     * raised by the service and rendered by `ApiExceptionRenderer` into the same
     * §66 envelope the `unique:users,email` rule produces. Catching `\RuntimeException`
     * here looked equivalent but was not — `QueryException` extends it too, so a
     * database failure was being reported to the client as a bad email address.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->userService->createUser([
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'phone' => $request->validated('phone'),
        ]);

        // Bearer-сессия из `user_sessions` (ТЗ §5), а не Sanctum: `config/auth.php`
        // объявляет guard `api` на драйвере `session_token`, и все защищённые
        // маршруты ходят через `auth:api`. `personal_access_tokens` в ТЗ нет.
        $token = $this->sessions->issue((int) $user->getKey(), ClientContext::fromRequest($request));

        return response()->json([
            'data' => [
                'user' => new AuthResource($user->load('roles')),
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

        $user->load('roles');
        $token = $this->sessions->issue((int) $user->getKey(), ClientContext::fromRequest($request));

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
     *
     * Завершает именно ту сессию, чей Bearer-токен пришёл в запросе. У
     * `user_sessions` нет колонки отзыва, поэтому строка удаляется — см.
     * `SessionIssuer::revoke()`; здесь только выбор сессии по токену.
     */
    public function logout(Request $request): Response
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            $this->sessions->revokeByPlainToken(
                $token,
                ClientContext::fromRequest($request),
                identifier: $request->user()?->email,
            );
        }

        return response(null, 204);
    }

    /**
     * Request password reset — NOT IMPLEMENTED.
     *
     * Kept as a routed endpoint so the API surface does not silently change, but
     * it answers 501 rather than calling a service method that does not exist.
     * The flow needs storage the schema does not have: `nabilet_core_spec` defines
     * no `password_reset_tokens` (or equivalent) table, and `docs/openapi.yaml`
     * does not document this path. Until both exist, any real implementation here
     * would be inventing a contract. Admin-driven resets go through
     * `UserService::updateUser()`.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        return $this->notImplemented('POST /api/v1/auth/forgot-password');
    }

    /**
     * Reset password with token — NOT IMPLEMENTED. See `forgotPassword()`.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        return $this->notImplemented('POST /api/v1/auth/reset-password');
    }

    /**
     * Verify email address — NOT IMPLEMENTED. See `forgotPassword()`.
     *
     * `users.email_verified_at` exists and registration stamps it, so there is no
     * verification state left to move; a token flow would need a table that the
     * schema does not define.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        return $this->notImplemented('POST /api/v1/auth/verify-email');
    }

    /** A uniform, greppable answer for the endpoints above. */
    private function notImplemented(string $endpoint): JsonResponse
    {
        return response()->json([
            'error' => 'Not Implemented',
            'message' => sprintf(
                '%s is routed but has no implementation: the schema defines no token storage for it.',
                $endpoint,
            ),
        ], 501);
    }
}
