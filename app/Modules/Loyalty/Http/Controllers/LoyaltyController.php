<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Events\Models\Event;

/**
 * Общий корень для «❤️» и «🔗»: оба контроллера решают одну и ту же задачу —
 * превратить публичный идентификатор мероприятия из пути в модель.
 *
 * ПОЧЕМУ РАЗРЕШЕНИЕ ЗДЕСЬ, А НЕ ПРИВЯЗКОЙ МОДЕЛИ В МАРШРУТЕ
 *   Путь принимает `public_id` (соглашение проекта: внутренний BIGINT не
 *   покидает API, `DATABASE.md` §9). Неявная привязка модели привела бы строку
 *   к int и открыла бы `/me/events/1/favorite` мероприятию #1 — тому же
 *   дефекту, из-за которого у `/me/sessions/{session}` стоит `whereNumber`.
 *   Поэтому методы принимают `string $event` и разрешают его сами.
 *
 *   Удалённое мероприятие отвечает 404, а не 410: оно всё ещё существует в
 *   чужом списке, но для текущего покупателя его больше нет. Трейт
 *   `SoftDeletes` у `Event` сам скрывает удалённые строки из этого запроса.
 */
abstract class LoyaltyController extends Controller
{
    protected function resolveEvent(string $publicId): Event
    {
        $event = Event::query()->where('public_id', $publicId)->first();

        if ($event === null) {
            throw new NotFoundError('Event', $publicId);
        }

        return $event;
    }
}
