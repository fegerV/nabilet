<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * Input failed validation at the boundary.
 *
 * Carries per-field errors so the frontend can attach messages to the exact
 * inputs instead of showing a generic banner (see ТЗ §62 — the schema importer
 * must report "Строка 152: Seat Number не указан").
 */
class ValidationError extends AppError
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages
     * @param  array<string, mixed>         $context
     */
    public function __construct(
        public readonly array $errors = [],
        string $message = 'The given data was invalid.',
        array $context = [],
    ) {
        parent::__construct($message, 'VALIDATION_ERROR', 422, $context);
    }

    /** @return list<string> */
    public function allMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $error;
            }
        }

        return $messages;
    }

    /**
     * Field errors live under `details.fields`, keeping `details` a consistent
     * namespace for extra keys rather than making it the field map itself.
     *
     *     {"error":{"code":"VALIDATION_ERROR","message":"…",
     *               "details":{"fields":{"email":["Email is required."]}}}}
     *
     * @return array{error: array<string, mixed>}
     */
    public function toResponse(string $requestId = ''): array
    {
        $response = parent::toResponse($requestId);

        $details = $this->context;
        if ($this->errors !== []) {
            $details['fields'] = $this->errors;
        }

        if ($details !== []) {
            $response['error']['details'] = $details;
        }

        return $response;
    }
}
