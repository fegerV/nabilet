<?php

declare(strict_types=1);

namespace Nabilet\Core\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Nabilet\Core\Errors\AppError;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\ValidationError;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Translates exceptions raised *by the framework* into the §66 envelope.
 *
 * Why this class exists
 * ---------------------
 * `AppError` covers everything the domain raises deliberately. But most failures a
 * real client sees are not raised by the domain at all — they come from Laravel:
 *
 *   - `$request->validate()` throws `ValidationException`
 *   - route-model binding throws `ModelNotFoundException`
 *   - `abort(404, …)` throws `NotFoundHttpException`
 *   - a missing/expired bearer token throws `AuthenticationException`
 *   - the rate limiter throws `ThrottleRequestsException`
 *
 * Left alone, each of those renders in the *framework's* shape, not ours:
 *
 *     {"message":"validation.required","errors":{"session_id":["validation.required"]}}
 *     {"message":"No query results for model [Nabilet\\Modules\\Events\\Models\\Event] 999999"}
 *     {"message":"Hall not found","exception":"NotFoundHttpException","trace":[…]}
 *
 * Only the third of those is even close, and it leaks a stack trace. So without this
 * mapper the §66 envelope is honoured on the paths the domain owns and violated on
 * every path the framework owns — which is most of them.
 *
 * Why it lives in `Core/Http` and not `Core/Errors`
 * -------------------------------------------------
 * `tools/verify-purity.php` guards `app/Core/Errors` (and every `Modules/*\/Domain`) as
 * framework-free, because `AppError` and its subclasses are the vocabulary the domain
 * speaks and a domain class must be loadable with no vendor at all. This class is the
 * opposite: it exists *only* to know about Illuminate and Symfony exception types, so
 * putting it there was a purity violation — the guard caught it, correctly, with 12
 * findings. It is an HTTP-layer adapter, and `app/Core/Http` is where the Laravel-aware
 * HTTP layer lives.
 *
 * Two rules this class enforces:
 *
 *  1. **A model's fully-qualified class name must never reach a client.** Laravel's
 *     default 404 message embeds it, which discloses the internal namespace layout
 *     and the module that owns the resource. The message is rebuilt from the bare
 *     class name instead.
 *
 *  2. **An unexpected throwable is a bug, so its message is replaced.** Only the
 *     `errorCode` survives; the real message goes to the log via `report()`.
 *     `APP_DEBUG=true` in production would otherwise publish stack traces, absolute
 *     filesystem paths and vendor paths to unauthenticated callers.
 */
final class ApiExceptionRenderer
{
    /**
     * @return JsonResponse|null `null` means "not ours to render" — the caller lets
     *                           Laravel handle it (used for non-JSON/web requests).
     */
    public static function render(\Throwable $e, Request $request): ?JsonResponse
    {
        $requestId = (string) ($request->attributes->get('request_id') ?? '');

        $appError = self::toAppError($e);

        if ($appError === null) {
            return null;
        }

        // Bugs are logged where they can be diagnosed, and the client gets a generic
        // 500. The status is preserved (the author's intent), but the message and the
        // specific `errorCode` are replaced: an attacker probing for a scoping bypass
        // must not learn from the body that a bypass exists.
        if (! $appError->operational) {
            report($e);

            $appError = new AppError(
                'Something went wrong. Please try again.',
                'INTERNAL_ERROR',
                $appError->status,
            );
        }

        return response()->json($appError->toResponse($requestId), $appError->status, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Map a throwable onto the envelope, or `null` when the throwable is one this
     * application has no opinion about.
     */
    public static function toAppError(\Throwable $e): ?AppError
    {
        // Already ours — including the non-operational case, which `render()` logs.
        if ($e instanceof AppError) {
            return $e;
        }

        if ($e instanceof ValidationException) {
            return new ValidationError($e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return AuthError::unauthenticated(
                $e->getMessage() !== '' ? $e->getMessage() : 'Authentication required.'
            );
        }

        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return new AuthError(
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                'FORBIDDEN',
                403,
            );
        }

        if ($e instanceof ModelNotFoundException) {
            return self::notFoundFromModel($e);
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return new AppError('This HTTP method is not supported for this endpoint.', 'METHOD_NOT_ALLOWED', 405);
        }

        if ($e instanceof ThrottleRequestsException) {
            return new AppError('Too many requests. Please try again later.', 'TOO_MANY_REQUESTS', 429);
        }

        if ($e instanceof NotFoundHttpException) {
            // Laravel wraps `ModelNotFoundException` in a `NotFoundHttpException`
            // inside `Handler::prepareException()`, so by the time a render callback
            // runs the original is only reachable via `getPrevious()`. Its message
            // embeds the model's fully-qualified class name — verified live:
            // `No query results for model [Nabilet\Modules\Events\Models\Event] 999999`.
            // That must never be published, so it is rebuilt from the bare name.
            $previous = $e->getPrevious();

            if ($previous instanceof ModelNotFoundException) {
                return self::notFoundFromModel($previous);
            }

            // `abort(404, 'Hall not found')` carries a developer-written message that is
            // safe to publish; a bare router 404 carries an empty one.
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'The requested resource was not found.';

            return new AppError($message, 'NOT_FOUND', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.';

            return new AppError($message, 'HTTP_' . $status, $status);
        }

        // Anything else is a bug. `operational: false` makes `render()` report it and
        // keeps the internal message out of the response body.
        return new AppError(
            'Something went wrong. Please try again.',
            'INTERNAL_ERROR',
            500,
            [],
            false,
        );
    }

    /**
     * Build a 404 that names the resource but never its namespace.
     *
     * `No query results for model [Nabilet\Modules\Events\Models\Event] 999999`
     * becomes code `EVENT_NOT_FOUND`, message `Event not found.`.
     */
    private static function notFoundFromModel(ModelNotFoundException $e): AppError
    {
        $model = $e->getModel();

        if ($model === null || $model === '') {
            return new AppError('The requested resource was not found.', 'NOT_FOUND', 404);
        }

        $basename = class_basename($model);

        // Built directly rather than via `NotFoundError`, whose code is derived as
        // `strtoupper($resource)` — that would yield `USERSESSION_NOT_FOUND` for
        // `UserSession`, whereas the snake-cased code is `USER_SESSION_NOT_FOUND`.
        return new AppError(
            sprintf('%s not found.', $basename),
            self::resourceCode($basename) . '_NOT_FOUND',
            404,
        );
    }

    /**
     * `UserSession` → `USER_SESSION`. Used to keep `errorCode` stable and readable
     * even though the class name is not published.
     */
    public static function resourceCode(string $basename): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $basename) ?? $basename;

        return strtoupper($snake);
    }
}
