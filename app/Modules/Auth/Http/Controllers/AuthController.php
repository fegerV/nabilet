<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use Nabilet\Modules\Auth\Http\Requests\RegisterRequest;
use Nabilet\Modules\Auth\Http\Requests\LoginRequest;
use Nabilet\Modules\Auth\Http\Requests\ResetPasswordRequest;
use Nabilet\Modules\Auth\Http\Requests\VerifyEmailRequest;
use Nabilet\Modules\Auth\Http\Resources\AuthResource;
use Nabilet\Modules\Auth\Services\AccountRecoveryService;
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
        private readonly AccountRecoveryService $recovery,
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
     * Request a password reset link by e-mail.
     *
     * ALWAYS 204, whether or not the address belongs to an account.
     *
     * That is the whole point of the endpoint's contract: a 404 for an unknown
     * address turns it into an account-enumeration oracle, and an attacker who can
     * ask "does this person shop here?" in a loop has a mailing list. The service
     * returns `void` precisely so this controller has nothing to branch on — the
     * asymmetry cannot leak by accident later.
     *
     * The rate limit (`throttle:auth`) is the other half: without it the endpoint
     * is also a way to mass-mail a victim from our own domain. It lives on the
     * route, not here, so it applies before validation runs.
     */
    public function forgotPassword(ForgotPasswordRequest $request): Response
    {
        $this->recovery->requestPasswordReset($request->validated('email'));

        return response()->noContent();
    }

    /**
     * Complete a password reset with the token from the e-mail.
     *
     * A rejected token is a 422 with a field error rather than a 401: this is not
     * an authentication failure — the caller is not sending credentials — it is an
     * unusable input, and the §66 envelope puts it where the form can render it.
     * One message covers expired, forged and already-used tokens on purpose; the
     * differences are not the caller's business and telling them apart helps only
     * someone probing.
     */
    public function resetPassword(ResetPasswordRequest $request): Response
    {
        $done = $this->recovery->resetPassword(
            $request->validated('token'),
            $request->validated('email'),
            $request->validated('password'),
        );

        if (! $done) {
            throw new ValidationError([
                'token' => ['Ссылка для сброса пароля недействительна или истекла.'],
            ], 'Ссылка для сброса пароля недействительна или истекла.', [], 'PASSWORD_RESET_TOKEN_INVALID');
        }

        return response()->noContent();
    }

    /**
     * Confirm an e-mail address from the link sent at registration.
     *
     * Requires an authenticated session (`auth:api` on the route) AND a valid
     * token. The session proves the caller is the account holder right now; the
     * token proves control of the mailbox. Either alone is weaker: a stolen
     * session should not be able to mark an address verified, and a forwarded link
     * should not work for a different logged-in user.
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        if (! $this->recovery->verifyEmail($request->validated('token'))) {
            throw new ValidationError([
                'token' => ['Ссылка подтверждения недействительна или истекла.'],
            ], 'Ссылка подтверждения недействительна или истекла.', [], 'EMAIL_VERIFICATION_TOKEN_INVALID');
        }

        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => $user === null ? null : new AuthResource($user->load('roles')),
            ],
        ]);
    }
}
