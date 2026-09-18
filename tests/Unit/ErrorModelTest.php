<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\AppError;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ExternalServiceError;
use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Tests\Support\TestCase;

/**
 * The error model is what makes the API contract stable: clients branch on
 * `errorCode`, never on message text. It also decides what is safe to show a user
 * versus what must be swallowed and logged.
 */
final class ErrorModelTest extends TestCase
{
    public function testAppErrorCarriesCodeStatusAndContext(): void
    {
        $error = new AppError('Something specific happened.', 'SOMETHING_SPECIFIC', 418, ['k' => 'v']);

        $this->assertSame('SOMETHING_SPECIFIC', $error->errorCode);
        $this->assertSame(418, $error->status);
        $this->assertSame(['k' => 'v'], $error->context);
        $this->assertTrue($error->operational);
        $this->assertTrue($error->isClientError());
    }

    public function testAppErrorDoesNotCollideWithExceptionCode(): void
    {
        // \Exception declares protected int $code; AppError must not redeclare it.
        $error = new AppError('msg', 'CODE', 400);

        $this->assertSame(0, $error->getCode());
        $this->assertSame('CODE', $error->errorCode);
    }

    public function testResponseEnvelopeShape(): void
    {
        $response = (new AppError('Bad seat.', 'SEAT_UNAVAILABLE', 409, ['seat' => 'A-12']))
            ->toResponse('req-123');

        $this->assertSame(['error'], array_keys($response));
        $this->assertSame('SEAT_UNAVAILABLE', $response['error']['code']);
        $this->assertSame('Bad seat.', $response['error']['message']);
        $this->assertSame(['seat' => 'A-12'], $response['error']['details']);
        $this->assertSame('req-123', $response['error']['request_id']);
    }

    public function testResponseOmitsEmptyDetailsAndRequestId(): void
    {
        $response = (new AppError('msg', 'CODE', 400))->toResponse();

        $this->assertFalse(array_key_exists('details', $response['error']));
        $this->assertFalse(array_key_exists('request_id', $response['error']));
    }

    /**
     * The HTTP status is transport-level and must not leak into the body: §66
     * defines the envelope as exactly code/message/details/request_id.
     */
    public function testStatusIsNotDuplicatedInTheBody(): void
    {
        $response = (new AppError('msg', 'CODE', 409))->toResponse();

        $this->assertFalse(array_key_exists('status', $response['error']));
        $this->assertSame(409, (new AppError('msg', 'CODE', 409))->status);
    }

    public function testServerErrorsAreNotClientErrors(): void
    {
        $this->assertFalse((new AppError('msg', 'CODE', 500))->isClientError());
    }

    // ------------------------------------------------------------- NotFound

    public function testNotFoundIsAlways404(): void
    {
        $error = new NotFoundError('Order', '1234');

        $this->assertSame(404, $error->status);
        $this->assertSame('ORDER_NOT_FOUND', $error->errorCode);
        $this->assertSame('Order not found: 1234.', $error->getMessage());
    }

    public function testNotFoundWithoutIdStillReadsWell(): void
    {
        $this->assertSame('Venue not found.', (new NotFoundError('Venue'))->getMessage());
    }

    // ----------------------------------------------------------- Validation

    public function testValidationErrorExposesFieldErrors(): void
    {
        $error = new ValidationError([
            'seats' => ['Seat A-12 is no longer available.'],
            'email' => ['Email is required.', 'Email must be valid.'],
        ]);

        $this->assertSame(422, $error->status);
        $this->assertSame('VALIDATION_ERROR', $error->errorCode);
        $this->assertCount(3, $error->allMessages());
        $this->assertSame(
            ['seats' => ['Seat A-12 is no longer available.'], 'email' => ['Email is required.', 'Email must be valid.']],
            $error->toResponse()['error']['details']['fields']
        );
    }

    // ------------------------------------------------------------- Conflict

    public function testSeatUnavailableIsA409WithAPreciseCode(): void
    {
        $error = ConflictError::seatUnavailable('inv-99');

        $this->assertSame(409, $error->status);
        $this->assertSame('SEAT_UNAVAILABLE', $error->errorCode);
        $this->assertSame('inv-99', $error->context['inventory_item_id']);
    }

    public function testHoldExpiredIsA409(): void
    {
        $error = ConflictError::holdExpired('hold-1');

        $this->assertSame('HOLD_EXPIRED', $error->errorCode);
        $this->assertSame(409, $error->status);
    }

    public function testIdempotencyConflictIsA409(): void
    {
        $error = ConflictError::idempotencyConflict('key-abc');

        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $error->errorCode);
        $this->assertSame('key-abc', $error->context['idempotency_key']);
    }

    // ----------------------------------------------------------------- Auth

    public function testUnauthenticatedIs401AndForbiddenIs403(): void
    {
        $this->assertSame(401, AuthError::unauthenticated()->status);
        $this->assertSame('TOKEN_EXPIRED', AuthError::tokenExpired()->errorCode);

        $forbidden = AuthError::forbidden('orders.refund');
        $this->assertSame(403, $forbidden->status);
        $this->assertSame('orders.refund', $forbidden->context['required_permission']);
    }

    // --------------------------------------------------------- Domain rules

    public function testImmutabilityViolationIsExplicit(): void
    {
        $error = DomainRuleViolation::immutable('HallSchemaVersion', 'seats');

        $this->assertSame('IMMUTABLE_RESOURCE', $error->errorCode);
        $this->assertSame(422, $error->status);
        $this->assertSame('HallSchemaVersion', $error->context['entity']);
    }

    public function testClosedSalesWindowIsExplicit(): void
    {
        $error = DomainRuleViolation::salesClosed('sess-7');

        $this->assertSame('SALES_CLOSED', $error->errorCode);
        $this->assertSame('sess-7', $error->context['session_id']);
    }

    // ------------------------------------------------------- Transitions

    public function testTransitionErrorExplainsWhatWasAllowed(): void
    {
        $error = new InvalidStateTransitionError('Order', 'refunded', 'paid', ['refunded']);

        $this->assertSame(409, $error->status);
        $this->assertSame('INVALID_STATE_TRANSITION', $error->errorCode);
        $this->assertTrue(str_contains($error->getMessage(), 'refunded'));
        $this->assertTrue(str_contains($error->getMessage(), 'paid'));
    }

    // ------------------------------------------------------ External services

    public function testExternalServiceErrorMarksRetryability(): void
    {
        $retryable = new ExternalServiceError('YooKassa', 'Gateway timeout', true);
        $permanent = new ExternalServiceError('YooKassa', 'Invalid shop id', false);

        $this->assertSame(502, $retryable->status);
        $this->assertSame('YOOKASSA_FAILED', $retryable->errorCode);
        $this->assertTrue($retryable->context['retryable']);
        $this->assertFalse($permanent->context['retryable']);
    }

    // --------------------------------------------------------------- Tenant

    /**
     * A missing tenant context is a programming error, not a user error: it must
     * be logged and reported as a generic 500, never rendered as guidance to a
     * caller who might then probe for a bypass.
     */
    public function testMissingTenantContextIsNonOperational(): void
    {
        $error = new TenantContextMissingError('Order');

        $this->assertFalse($error->operational);
        $this->assertSame(500, $error->status);
        $this->assertSame('TENANT_CONTEXT_MISSING', $error->errorCode);
        $this->assertSame('Order', $error->context['model']);
    }

    public function testEveryErrorIsAnAppErrorSoTheGlobalHandlerCatchesIt(): void
    {
        $errors = [
            new NotFoundError('Order'),
            new ValidationError([]),
            ConflictError::seatUnavailable('x'),
            AuthError::unauthenticated(),
            DomainRuleViolation::immutable('X', 'y'),
            new InvalidStateTransitionError('M', 'a', 'b'),
            new ExternalServiceError('S', 'm'),
            new TenantContextMissingError(),
        ];

        foreach ($errors as $error) {
            $this->assertTrue($error instanceof AppError, $error::class . ' must extend AppError');
            $this->assertTrue($error->errorCode !== '', $error::class . ' must define an errorCode');
        }
    }
}
