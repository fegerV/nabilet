<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Support;

use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Tickets\Models\Ticket;

/**
 * `ticket_id` из тела sync-запроса → `tickets.public_id`.
 *
 * ЗАЧЕМ ИМЕННО PUBLIC_ID
 *   Офлайн-бандл (`OfflineBundleBuilder`) отдаёт устройству public_id билета, и
 *   `TicketScanService::scanFromBundle()` принимает именно его. Поэтому внешний
 *   идентификатор тут — не вторичная форма, а основная.
 *
 * ПОЧЕМУ ЧИСЛО ТОЖЕ РАБОТАЕТ
 *   Ноль — не «не передали», а валидный id в MySQL не бывает; `0` означает
 *   «билет не указан», поэтому числу `0` соответствует отказ, а не поиск.
 *   Любой другой числовой id разрешается в public_id — иначе пакетная выгрузка
 *   из отчёта (`/admin/checkin/scans` отдаёт оба идентификатора) не пережила бы
 *   обратную отправку.
 */
final class TicketLocator
{
    public function publicIdOf(string|int $value): string
    {
        $value = is_int($value) ? (string) $value : trim($value);

        if ($value === '') {
            throw new ValidationError(['ticket_id' => ['Ticket id is required.']]);
        }

        if (preg_match('/^\d{1,19}$/', $value) === 1) {
            $id = (int) $value;

            if ($id === 0) {
                throw new ValidationError(['ticket_id' => ['Unknown ticket id.']]);
            }

            $publicId = Ticket::query()->whereKey($id)->value('public_id');

            if ($publicId === null) {
                throw new ValidationError(['ticket_id' => ['Unknown ticket id.']]);
            }

            return (string) $publicId;
        }

        $exists = Ticket::query()->where('public_id', $value)->exists();

        if (! $exists) {
            throw new ValidationError(['ticket_id' => ['Unknown ticket public id.']]);
        }

        return $value;
    }
}
