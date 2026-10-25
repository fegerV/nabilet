<?php

declare(strict_types=1);

namespace Nabilet\Core\Database;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Core\Errors\NotFoundError;
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
 * (Обе приватные методы удалены: разрешение и отрус при «не нашли» теперь
 * `firstOrFail()` ниже.)
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

    /**
     * Разрешить идентификатор или отказать.
     *
     * ЗАЧЕМ. Политика «не нашлось — 404» стояла двумя побайтово одинаковыми
     * приватными методами в двух контроллерах Checkin:
     *
     *   - `CheckinDeviceController::find()`
     *   - `OfflineBundleController::findDevice()`
     *
     * Извлечение `first()` закрыло только половину: способ построить запрос
     * стал общим, а способ на него отреагировать — нет. Разойдясь, эти две
     * копии дали бы разный код ошибки или молча ушли в 500 на одном из двух
     * путей админки, открывающих одно и то же устройство.
     *
     * ПОЧЕМУ ИМЕННО ЗДЕСЬ. Владелец разрешения идентификатора уже этот класс;
     * «не нашли» — такая же часть правила, как «как искали». Отдельный хелпер
     * означал бы, что половина правила живёт в `Core/Database`, а половина —
     * у вызывающего, и снова требует синхронизации.
     *
     * `$resource` — параметр, а не производная от имени класса: подпись в
     * ошибке это текст контракта («Device not found: X.»), и он не обязан
     * совпадать с именем PHP-класса. Выводить его автоматически значило бы
     * менять сообщение об ошибке при переименовании модели.
     *
     * @template TModel of Model
     * @param  class-string<TModel>  $model
     * @param  string  $resource  имя ресурса для NotFoundError
     * @return TModel
     *
     * @throws NotFoundError
     */
    public static function firstOrFail(string $model, string $value, string $resource): Model
    {
        $found = self::first($model, $value);

        if ($found === null) {
            throw new NotFoundError($resource, $value);
        }

        return $found;
    }
}
