<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Modules\Loyalty\Domain\ReferralCode as ReferralCodeRules;
use Nabilet\Modules\Loyalty\Models\BonusTransaction;
use Nabilet\Modules\Loyalty\Models\Referral;
use Nabilet\Modules\Loyalty\Models\ReferralCode;
use Nabilet\Modules\Orders\Models\Order;

/**
 * Реферальная программа «Клуба зрителей» (фаза 4 `docs/LOYALTY-PLAN.md`).
 *
 * СХЕМА РАБОТЫ
 *   Кабинет выдаёт ссылку `/r/{code}` → витрина запоминает код
 *   (`lib/referral.ts`) → чекаут присылает его в теле оформления →
 *   `attachToOrder()` заводит приглашение → первая ОПЛАТА друга запускает
 *   `RewardReferralJob` → `rewardForOrder()` начисляет бонусы обеим сторонам.
 *
 * ПОЧЕМУ АТРИБУЦИЯ — НА ЧЕКАУТЕ, А НЕ ПО КЛИКУ
 *   Клик по ссылке не создаёт приглашения: иначе список «приглашённых» в
 *   кабинете врал бы, показывая всех, кто открыл ссылку и закрыл вкладку.
 *   Приглашение рождается из заказа — документа, в котором есть адрес друга
 *   (`customer_email`, NOT NULL) и, после `resolveBuyerId()`, аккаунт.
 *
 * ПОЧЕМУ ДРУГ — ЭТО АДРЕС, А НЕ АККАУНТ
 *   Ссылку открывают до регистрации, и `user_id` в этот момент не существует.
 *   Адрес известен и до, и после: по нему приглашение находится, а
 *   `invited_user_id` проставляется, когда аккаунт заведён (см. `Referral`).
 *
 * ПОЧЕМУ НЕИЗВЕСТНЫЙ КОД — МОЛЧА, А НЕ ОШИБКОЙ
 *   Код в ссылке — не промокод: отказ (422) сломал бы покупку из-за
 *   устаревшей или опечатанной ссылки, а безопасность от этого не растёт —
 *   неизвестный код просто не даёт награды. Самоприглашение молча тоже:
 *   покупатель, открывший свою же ссылку, не ошибся, а покупка его законна.
 *
 * ПОЧЕМУ НАГРАДА — ПОСЛЕ ОПЛАТЫ, А НЕ ПОСЛЕ ЧЕКАУТА
 *   «Первая оплата друга» — денежное событие, и начислять за неоплаченный
 *   заказ значит платить за намерение. Статус `paid` приходит из нескольких
 *   мест (см. `OrderBonusObserver`), поэтому награда висит на обсервере, а не
 *   на вызове из одного сценария оплаты.
 */
final class ReferralService
{
    public function __construct(
        private readonly BonusService $bonus = new BonusService(),
        private readonly LoyaltySettingsService $settings = new LoyaltySettingsService(),
    ) {
    }

    /**
     * Код приглашения покупателя — выдаётся лениво, при первом обращении.
     *
     * Ленивый, а не при регистрации: покупатель по умолчанию — гость, и
     * заводить код каждому, кто открыл афишу, значило бы держать таблицу
     * ссылок, которыми никто не пользуется.
     */
    public function codeFor(User $user): string
    {
        return ReferralCode::forUserOrCreate($user->getKey())->code;
    }

    /**
     * Ссылка приглашения для кабинета: `/#/r/{code}` от адреса приложения.
     *
     * Тот же хост, что и витрина: `config('app.url')` — единственный источник,
     * и зашитый домен в коде расходился бы с окружением при первом же
     * переносе.
     *
     * ХЭШ-ПУТЬ, А НЕ ЧИСТЫЙ `/r/{code}`: SPA отдаётся ТОЛЬКО на `/`
     * (`routes/web.php`), а всё остальное уходит в `Route::fallback()` — чистый
     * путь дал бы брендированную 404 вместо страницы приглашения. Тот же
     * инвариант, по которому ссылки в письмах несут `/#/reset-password?...`
     * (`AccountMailService::actionUrl()`): hash-роутинг работает без переписывания
     * URL на сервере, и витрина открывается на любом хостинге.
     */
    public function linkFor(User $user): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base . '/#/r/' . $this->codeFor($user);
    }

    /**
     * Статистика приглашений для кабинета: сколько друзей пришло по ссылке,
     * сколько из них оплатили первый заказ и сколько бонусов это принесло.
     *
     * `earned` считается по ЖУРНАЛУ (`bonus_transactions`, причина
     * `referral_inviter`), а не по строкам `referrals`: настройка награды могла
     * поменяться между приглашениями, и «₽300 за каждого» перестало бы
     * сходиться с фактом. Журнал — доказательство, `referrals` — учёт.
     *
     * @return array{invited: int, rewarded: int, earned: int}
     */
    public function statsFor(User $user): array
    {
        $inviterId = $user->getKey();

        return [
            'invited' => Referral::query()->forInviter($inviterId)->count(),
            'rewarded' => Referral::query()->forInviter($inviterId)->rewarded()->count(),
            'earned' => (int) BonusTransaction::query()
                ->where('user_id', $inviterId)
                ->where('reason', BonusReason::REFERRAL_INVITER)
                ->sum('delta'),
        ];
    }

    /**
     * Завести (или уточнить) приглашение по оплачиваемому заказу друга.
     *
     * Вызывается из `CartCheckoutService::convert()` в той же транзакции, что
     * создаёт заказ: откат оформления откатывает и приглашение, и наоборот —
     * заказ без приглашения невозможен, если код был признан.
     *
     * @return Referral|null null — нет кода, программа выключена, код чужой/
     *                    устаревший, самоприглашение или приглашение уже
     *                    использовано этим адресом
     */
    public function attachToOrder(Order $order, string $code): ?Referral
    {
        if (!$this->settings->forAdmin()['referral_enabled']) {
            return null;
        }

        $normalized = ReferralCodeRules::normalize($code);

        if (!ReferralCodeRules::isWellFormed($normalized)) {
            return null;
        }

        $link = ReferralCode::query()->withCode($normalized)->first();

        if ($link === null) {
            return null;
        }

        $email = (string) $order->customer_email;

        if ($email === '') {
            return null;
        }

        $inviterId = (int) $link->user_id;
        $buyerId = $order->user_id !== null ? (int) $order->user_id : null;

        // Самоприглашение двумя путями: по аккаунту и по адресу. Второе —
        // для гостевого чекаута, где `user_id` ещё null, но адрес совпадает с
        // пригласившим. Без этой проверки «пригласи себя» давало бы бонусы за
        // любой заказ.
        if ($buyerId !== null && $buyerId === $inviterId) {
            return null;
        }

        if ($link->user !== null && strcasecmp($email, (string) $link->user->email) === 0) {
            return null;
        }

        // Адрес уже использовал своё приглашение с ДРУГИМ пригласившим:
        // второе не заводим, иначе один друг приносил бы награду каждому,
        // чью ссылку открыл. Уточнение `rewarded` — именно этого пригласившего.
        $alreadyRewarded = Referral::query()
            ->where('invited_email', strtolower($email))
            ->rewarded()
            ->exists();

        if ($alreadyRewarded) {
            return null;
        }

        return Referral::upsertByEmail($inviterId, $email, $buyerId);
    }

    /**
     * Награда обеим сторонам за первую оплату друга. Идемпотентно.
     *
     * ИДЕМПОТЕНТНОСТЬ — ДВУМЯ МЕХАНИЗМАМИ
     *   Строка приглашения уходит в `rewarded` (и повтор её не подберёт —
     *   `scopeAwaitingReward`), а каждая выплата защищена ключом
     *   `uq_bonus_transactions_dedupe`. Два механизма, а не один: статус
     *   приглашения мог не записаться при сбое между вставками журнала, и
     *   тогда джоба повторится — второй раз баланс не сдвинется.
     *
     * ПОЧЕМУ ВОЗВРАТ НЕ ОТОЗЫВАЕТ НАГРАДУ
     *   Ограничение намеренное и то же, что у кэшбэка в фазе 2b: бонус уже на
     *   счёте, и отзыв потребовал бы решения «что делать, если друг уже
     *   потратил оба бонуса». Записано в `docs/LOYALTY-PLAN.md`.
     *
     * @return Referral|null null — награда не полагается (выключена, некому
     *                    платить, самоприглашение) или уже выплачена
     */
    public function rewardForOrder(Order $order): ?Referral
    {
        $reward = $this->settings->referralReward();

        // Выключенная программа не платит и НЕ помечает приглашение
        // награждённым: включение позже должно оставлять возможность
        // доначислить вручную, а статус `rewarded` без выплаты соврал бы.
        if (!$reward->enabled) {
            return null;
        }

        $buyerId = $order->user_id !== null ? (int) $order->user_id : null;

        $referral = Referral::query()
            ->awaitingReward($buyerId, (string) $order->customer_email)
            ->first();

        if ($referral === null) {
            return null;
        }

        $inviterId = (int) $referral->inviter_user_id;

        if ($buyerId !== null && $buyerId === $inviterId) {
            // Защита от самоприглашения на случай, если строка завелась без
            // `attachToOrder()` (сид, миграция данных): платить самому себе
            // за свой заказ нельзя.
            return null;
        }

        $inviter = User::query()->find($inviterId);

        if ($inviter === null) {
            // Пригласивший удалён — каскад унёс бы и приглашение, но если
            // строка ещё жива (гонка), платить некому.
            return null;
        }

        $currency = (string) ($order->currency ?: 'RUB');
        $invited = $buyerId !== null ? User::query()->find($buyerId) : null;

        DB::transaction(function () use ($reward, $inviter, $invited, $order, $referral, $currency): void {
            if ($reward->paysInviter()) {
                $this->bonus->credit(
                    $inviter,
                    Money::of($reward->inviterAmount, $currency),
                    BonusReason::REFERRAL_INVITER,
                    BonusService::dedupeKeyForReferralInviter($referral->getKey()),
                    $order,
                );
            }

            // Награда другу — один раз на человека, а не на приглашение:
            // иначе друг, открывший две ссылки и оплативший два заказа,
            // получал бы её дважды. Проверка по журналу, а не по флагу:
            // журнал — доказательство выплаты (см. `BonusService::credit`).
            if ($reward->paysInvited() && $invited !== null && !$this->hasInvitedReward($invited)) {
                $this->bonus->credit(
                    $invited,
                    Money::of($reward->invitedAmount, $currency),
                    BonusReason::REFERRAL_INVITED,
                    BonusService::dedupeKeyForReferralInvited($referral->getKey()),
                    $order,
                );
            }

            $referral->forceFill([
                'status' => Referral::STATUS_REWARDED,
                'bonus_paid_at' => now(),
            ])->save();
        });

        return $referral;
    }

    /** Другу уже начисляли награду за приглашение — по любой ссылке. */
    private function hasInvitedReward(User $user): bool
    {
        return BonusTransaction::query()
            ->where('user_id', $user->getKey())
            ->where('reason', BonusReason::REFERRAL_INVITED)
            ->exists();
    }
}
