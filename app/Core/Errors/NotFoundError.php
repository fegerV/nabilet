<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * A requested resource does not exist — or does not exist *for this tenant*.
 *
 * Note the second clause: cross-tenant reads return 404, never 403. A 403 would
 * confirm that the id exists somewhere in the system, leaking the existence of
 * other organizations' data (IDOR/BOLA enumeration).
 */
class NotFoundError extends AppError
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $resource,
        public readonly string|int $resourceId = '',
        array $context = [],
    ) {
        parent::__construct(
            $resourceId === ''
                ? sprintf('%s not found.', $resource)
                : sprintf('%s not found: %s.', $resource, $resourceId),
            self::codeFor($resource),
            404,
            $context,
        );
    }

    /**
     * SCREAMING_SNAKE код ошибки по имени ресурса.
     *
     * `strtoupper('Hall row')` дал бы «HALL ROW_NOT_FOUND» — с пробелом внутри
     * константы; контракт требует HALL_ROW_NOT_FOUND. Поэтому пробелы и дефисы
     * превращаются в подчёркивания (snake_case-имена при этом не меняются:
     * 'promo_code' -> PROMO_CODE_NOT_FOUND). Это единственный генератор кода в
     * этом классе: вызывающий не может передать в `resource` текст сообщения и
     * получить на провод «CART NOT FOUND._NOT_FOUND» (дефект, из-за которого
     * клиентский бранчинг по `error.code` молча ломался).
     */
    private static function codeFor(string $resource): string
    {
        return strtoupper(str_replace([' ', '-'], '_', trim($resource))) . '_NOT_FOUND';
    }
}
