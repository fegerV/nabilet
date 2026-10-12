<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Support;

use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * `session_id` из тела запроса → внутренний `sessions.id`.
 *
 * ЗАЧЕМ
 *   Контракт объявляет `session_id: { type: string }` (`openapi.yaml`,
 *   `CheckinValidateRequest`/`CheckinUseRequest`/`CheckinSyncRequest`), а
 *   `sessions` имеет и `id`, и `public_id`. Устройство работает с тем, что
 *   получило в бандле; бандл несёт public_id, административный чекер исторически
 *   присылал число. Принимать надо оба, иначе один из двух клиентов молча
 *   ломается.
 *
 * ПОЧЕМУ НЕ `intval()`
 *   `intval('012abc')` даёт 12 — и запрос уходит к чужому сеансу. Здесь либо
 *   целое число, либо public_id, и всё остальное — 422 с внятным полем.
 */
final class SessionLocator
{
    public function resolve(string|int $value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,19}$/', $value) === 1)) {
            $id = (int) $value;
            $found = Session::query()->whereKey($id)->value('id');

            if ($found !== null) {
                return (int) $found;
            }

            throw new ValidationError(['session_id' => ['Unknown session id.']]);
        }

        $id = Session::query()->where('public_id', $value)->value('id');

        if ($id === null) {
            throw new ValidationError(['session_id' => ['Unknown session public id.']]);
        }

        return (int) $id;
    }
}
