<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Models\Concerns;

use Nabilet\Modules\Core\Support\PublicId;

/**
 * Заполнить `public_id` при создании модели.
 *
 * ЗАЧЕМ ТРЕЙТ. Один и тот же `booted()` с одним и тем же `creating`-хуком был
 * скопирован в 32 модели. Отличались они только именем переменной в замыкании
 * (`$model`, `$cart`, `$event`, `$row`…) — то есть это была буквально копия,
 * и правка «сменим генератор» означала 32 правки в 32 файлах.
 *
 * ПОЧЕМУ `bootHasPublicId()`, А НЕ `booted()`. Laravel сам зовёт
 * `boot{TraitName}` при загрузке модели, поэтому модель, использующая трейт, не
 * обязана объявлять `booted()` вовсе. Модель, у которой `booted()` есть по
 * другой причине, продолжит работать: хуки трейта и `booted()` не конфликтуют.
 *
 * ПОЧЕМУ ПРОВЕРКА НА `''`, А НЕ ТОЛЬКО НА `null`. 31 копия проверяла
 * `=== null`, а `LoyaltyLevel` — `empty()`, то есть расхождение уже началось.
 * `''` — это «не задан» ровно так же, как `null` (в `CHAR(26) NOT NULL` пустая
 * строка выглядит как настоящий id, но им не является), поэтому трейт закрывает
 * оба случая. Значение, заданное вызывающим, не трогается никогда: сиды и
 * тесты вправе вписать id явно.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if ($model->public_id === null || $model->public_id === '') {
                $model->public_id = PublicId::generate();
            }
        });
    }
}
