<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Расход промокода — одна строка на пару (заказ, код).
 *
 * ДЕФЕКТ, КОТОРЫЙ ЗДЕСЬ ЗАКРЫТ
 *
 *   Класс описывал не ту таблицу, которая существует. `promo_code_redemptions`
 *   не имеет ни `created_at`, ни `updated_at` — время расхода живёт в
 *   `redeemed_at`. Модель же не отключала метки времени и не объявляла
 *   `redeemed_at` заполняемым, поэтому `PromoCodeRedemption::create()`
 *   (единственный вызов — `PromoCodeService::redeem()`, то есть ЛЮБОЙ чекаут с
 *   промокодом) падал с
 *
 *       SQLSTATE[42S22]: Unknown column 'updated_at' in 'field list'
 *
 *   то есть 500 на оформлении заказа, а не «код не применился». Ни один тест
 *   этого не ловил: `PromoEvaluatorCheckoutBridgeTest` проверяет чистый движок цен
 *   (`PromoEvaluator::evaluate`), а путь ЗАПИСИ не вызывался нигде.
 *
 *   За первым дефектом стоял второй, тихий: `user_id` и `redeemed_at` не были в
 *   `$fillable`, поэтому массовое присваивание их ОТБРАСЫВАЛО. Даже если бы
 *   `updated_at` не мешал, `user_id` остался бы NULL, и `per_user_limit` не
 *   считался бы ни для кого — гость или вошедший покупатель, разницы нет.
 *   `redeemed_at` в той же ситуации — NOT NULL без значения по умолчанию, то есть
 *   ошибка 1364 «Field 'redeemed_at' doesn't have a default value». Оба поля
 *   теперь заполняемы, и это не косметика: без `user_id` в этой строке лимит на
 *   покупателя недоказуем.
 *
 * ЧТО ИМЕННО ЧИТАЕТ ЭТУ ТАБЛИЦУ
 *   `PromoCodeService::userRedemptions()` — `count()` по `promo_code_id` +
 *   `user_id`. Это единственный потребитель, и он же объясняет, почему колонка
 *   `user_id` обязана быть заполнена, а не «может быть NULL у гостя».
 */
class PromoCodeRedemption extends Model
{
    protected $table = 'promo_code_redemptions';

    /**
     * У таблицы нет `created_at` / `updated_at` — только `redeemed_at`.
     *
     * `false`, а не `const CREATED_AT = 'redeemed_at'`: второе заставило бы
     * Eloquent искать ещё и `updated_at` (он выводится из `CREATED_AT` заменой
     * `created` на `updated`), которого нет. Строка расхода неизменяема — это
     * запись о событии, а не редактируемая сущность.
     */
    public $timestamps = false;

    protected $fillable = [
        'promo_code_id',
        'order_id',
        'user_id',
        'discount_amount',
        'redeemed_at',
    ];

    protected $casts = [
        'discount_amount' => 'string',
        'redeemed_at' => 'datetime:Y-m-d H:i:s.u',
    ];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
