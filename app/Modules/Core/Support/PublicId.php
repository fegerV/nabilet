<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Support;

use Illuminate\Support\Str;

/**
 * `public_id` — единственный владелец правила «как выглядит публичный id».
 *
 * ЗАЧЕМ. `public_id` — это не внутренний ключ, а ЧАСТЬ КОНТРАКТА: он стоит в
 * URL (`/venue/{slug}`, `/events/{publicId}`), в QR билета и в вебхуках. При
 * этом выражение `Str::ulid()->toBase32()` было выписано в 46 файлах: 32 модели
 * в `booted()`, два места вне его и восемь сервисов/контроллеров, которые
 * создают строку не через модель.
 *
 * Копии пока не разошлись — все дают 26 символов base32, и колонки объявлены
 * `CHAR(26)`. Но цена копии здесь выше обычной, потому что расхождение не
 * падает: `Str::uuid()->toString()` — это 36 символов в `CHAR(26)`, и MySQL в
 * нестрогом режиме молча обрежет его до 26. Именно это уже случалось однажды
 * (см. комментарий в `UserRepository`: «It used to be
 * `Str::uuid()->toString()` — 36 characters into a `CHAR(26)`»), и стоило
 * отдельного разбора.
 *
 * ПОЧЕМУ `toBase32()`, А НЕ `(string) Str::ulid()`. Они дают одинаковый
 * результат, но `toBase32()` называет формат явно. `(string)` полагается на
 * `__toString()` библиотеки Symfony, и это неявная зависимость: смена
 * представления по умолчанию изменила бы все id разом.
 *
 * ПОЧЕМУ НЕ В `app/Core/Support`. Там framework-agnostic ядро (см.
 * `tools/verify-purity.php`), а здесь нужен `Illuminate\Support\Str`.
 */
final class PublicId
{
    /** Длина `public_id` — она же ширина колонки `CHAR(26)` в спеке. */
    public const LENGTH = 26;

    /**
     * Новый публичный id: ULID в base32, 26 символов.
     *
     * ULID, а не UUID: он лексикографически сортируется по времени создания,
     * поэтому `public_id` можно использовать как курсор пагинации и как
     * tie-breaker в `ORDER BY`, не добавляя `created_at`.
     */
    public static function generate(): string
    {
        return Str::ulid()->toBase32();
    }
}
