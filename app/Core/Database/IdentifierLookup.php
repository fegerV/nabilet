<?php

declare(strict_types=1);

namespace Nabilet\Core\Database;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Core\Support\DatabaseId;

/**
 * «Число в пути — это внутренний id, всё остальное — public_id».
 *
 * ЗАЧЕМ. Пара строк
 *
 *     DatabaseId::isNumeric($value)
 *         ? $query->whereKey((int) $value)->first()
 *         : $query->where('public_id', $value)->first();
 *
 * стояла в трёх местах, и два из них — побайтово одинаковые приватные методы в
 * двух контроллерах Checkin:
 *
 *   - `Checkin\Http\Controllers\Admin\CheckinDeviceController::find()`
 *   - `Checkin\Http\Controllers\Admin\OfflineBundleController::findDevice()`
 *   - `Orders\Http\Controllers\OrderController::refund()` (тем же порядком,
 *     но без выделения в метод)
 *
 * Правило («один идентификатор в контракте, два способа его понять») описано у
 * `DatabaseId`, но само построение запроса копировали. Копии в одном модуле —
 * это два способа открыть устройство в админке; разойдясь, они дали бы 404 или,
 * что хуже, устройство по номеру вместо устройства по public_id.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В `Core\Support`. Владельцу нужен `Model`, то есть
 * `vendor/`, а `Core/Support` обязан работать без него — это проверяет
 * `tools/verify-purity.php`. Вопрос «это число?» остался чистым и живёт в
 * `DatabaseId`; здесь — только сборка запроса по его ответу.
 *
 * ПОЧЕМУ `whereKey((int) $value)`, А НЕ `where('id', $value)`. Одинаково
 * работает в MySQL, но `whereKey` говорит, что это первичный ключ, а не
 * случайная колонка с таким именем, и не требует от читателя помнить про
 * неявное приведение.
 *
 * Fail-closed: нераспознанное значение уходит в `public_id`, а не молча
 * приводится к int. `'012abc'` через `intval()` даёт 12 — и запрос уходит к
 * чужой записи; здесь такой путь ищется как public_id и не находится.
 *
 * @see DatabaseId — правило «это id?», которое этот класс применяет
 */
final class IdentifierLookup
{
    /**
     * @template TModel of Model
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    public static function first(string $model, string $value): ?Model
    {
        /** @var TModel $instance */
        $instance = new $model();

        $query = $instance->newQuery();

        return DatabaseId::isNumeric($value)
            ? $query->whereKey((int) $value)->first()
            : $query->where('public_id', $value)->first();
    }
}
