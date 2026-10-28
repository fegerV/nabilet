<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Nabilet\Modules\Core\Settings\SettingsStore;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\ReferralPolicy;
use Nabilet\Modules\Loyalty\Domain\ReferralReward;

/**
 * Настройки «Клуба зрителей»: ранний доступ (фаза 3b) и реферальная программа
 * (фаза 4).
 *
 * ГЛОБАЛЬНЫЙ СДВИГ, А НЕ ПОЛЯ НА СЕАНСЕ
 *   Ранний доступ — одно правило на весь магазин: покупатель, достигший уровня
 *   не ниже порога, входит в продажу на N часов раньше общего старта
 *   (`sessions.sales_start_at`). Порог и N часов правятся в админке и живут в
 *   таблице `settings` (scope=`loyalty`) — том же key/value-хранилище, что и
 *   SMTP (`MailSettings`, scope=`mail`), чтобы не заводить под три значения
 *   отдельную таблицу и миграцию.
 *
 * ПОРОГ — УРОВЕНЬ КЛУБА, А НЕ ПРОИЗВОЛЬНОЕ ЧИСЛО
 *   Настройка хранит SLUG уровня (`friend`/`vip`), а сравнивается по его
 *   `min_paid_orders`. Порядок задаёт `loyalty_levels`; сменился сид — правило
 *   продолжает работать, потому что опирается на данные таблицы, а не на
 *   зашитый список. Несуществующий slug (настройка из удалённого уровня)
 *   трактуется как «выключено» — fail-closed, доступ открывается по общему окну.
 *
 * ЧТО СЧИТАЕТСЯ «КЛУБНЫМ»
 *   Живой COUNT оплаченных заказов через `LoyaltyLevelService` — тот же
 *   источник, что и `/me/loyalty-level`. Не столбец `users.loyalty_level_id`:
 *   он материализован обсервером и может отставать (например, заказ оплачен, а
 *   очередь ещё не отработала), а ранний доступ — это продажа: лучше здесь
 *   лишний один COUNT, чем купивший «слишком рано».
 */
final class LoyaltySettingsService
{
    public const SCOPE = 'loyalty';

    public const KEY_ENABLED = 'early_access_enabled';
    public const KEY_HOURS = 'early_access_hours';
    public const KEY_MIN_LEVEL_SLUG = 'early_access_min_level_slug';

    public const KEY_REFERRAL_ENABLED = 'referral_enabled';
    public const KEY_REFERRAL_INVITER_BONUS = 'referral_inviter_bonus';
    public const KEY_REFERRAL_INVITED_BONUS = 'referral_invited_bonus';

    /** Дефолты: выключено, сутки, «друг» — тот же порог, что и у бонусов. */
    public const DEFAULT_HOURS = 24;
    public const DEFAULT_MIN_LEVEL_SLUG = 'friend';
    /** Верхний предел N часов: 30 суток. Дальше — почти «всегда», смысла нет. */
    public const MAX_HOURS = 720;

    /**
     * Дефолты рефералки (фаза 4): программа включена, ₽300 обеим сторонам.
     *
     * ПОЧЕМУ ВКЛЮЧЕНА ПО УМОЛЧАНИЮ
     *   Ссылка `/r/{code}` не рассылается никуда сама: её даёт кабинет
     *   приглашающему, и тот решает, кому её отправить. Включённая по
     *   умолчанию программа — это обещание, которое видит только тот, кто
     *   получил ссылку, то есть ровно тот, кому оно адресовано.
     *
     * ПОЧЕМУ 300 ₽
     *   ₽300 — визитная карточка «первой покупки» из таблицы правил плана;
     *   друг получает столько же за первый оплаченный заказ по приглашению,
     *   а пригласивший — ту же сумму за приведённого друга. Итоговая
     *   награда в ₽600 на пару — в пределах разумного при среднем чеке.
     */
    public const DEFAULT_REFERRAL_ENABLED = true;
    public const DEFAULT_REFERRAL_INVITER_BONUS = 30000;
    public const DEFAULT_REFERRAL_INVITED_BONUS = 30000;

    /** Чтение/запись `settings`; кэш не нужен — настроек три, читаются редко. */
    private SettingsStore $store;

    public function __construct(
        private readonly LoyaltyLevelService $levels = new LoyaltyLevelService(),
    ) {
        $this->store = new SettingsStore();
    }

    /**
     * Настройки для формы админки: значения плюс список уровней — выпадающий
     * список порога собирается из тех же данных, по которым считается уровень.
     *
     * @return array{
     *     enabled: bool,
     *     hours: int,
     *     min_level_slug: string,
     *     referral_enabled: bool,
     *     referral_inviter_bonus: int,
     *     referral_invited_bonus: int,
     *     levels: list<array{slug: string, name: string, min_paid_orders: int}>
     * }
     */
    public function forAdmin(): array
    {
        $values = $this->storedValues();

        $hours = (int) ($values[self::KEY_HOURS] ?? self::DEFAULT_HOURS);

        return [
            'enabled' => array_key_exists(self::KEY_ENABLED, $values)
                ? (bool) $values[self::KEY_ENABLED]
                : false,
            'hours' => $this->clampHours($hours),
            'min_level_slug' => (string) ($values[self::KEY_MIN_LEVEL_SLUG] ?? self::DEFAULT_MIN_LEVEL_SLUG),
            'referral_enabled' => array_key_exists(self::KEY_REFERRAL_ENABLED, $values)
                ? (bool) $values[self::KEY_REFERRAL_ENABLED]
                : self::DEFAULT_REFERRAL_ENABLED,
            'referral_inviter_bonus' => ReferralPolicy::normalize((int) ($values[self::KEY_REFERRAL_INVITER_BONUS] ?? self::DEFAULT_REFERRAL_INVITER_BONUS)),
            'referral_invited_bonus' => ReferralPolicy::normalize((int) ($values[self::KEY_REFERRAL_INVITED_BONUS] ?? self::DEFAULT_REFERRAL_INVITED_BONUS)),
            'levels' => $this->levels->tiers(),
        ];
    }

    /**
     * Сохранить настройки раннего доступа из админки. Валидация — за
     * контроллером; здесь только нормализация часов в допустимый диапазон.
     */
    public function save(bool $enabled, int $hours, string $minLevelSlug): void
    {
        $this->writeValue(self::KEY_ENABLED, $enabled);
        $this->writeValue(self::KEY_HOURS, $this->clampHours($hours));
        $this->writeValue(self::KEY_MIN_LEVEL_SLUG, $minLevelSlug);
    }

    /**
     * Сохранить настройки реферальной программы из админки (фаза 4).
     *
     * Отдельный метод, а не пятый параметр `save()`: ранний доступ и
     * рефералка правятся разными формами, и один метод с двумя необязательными
     * группами параметров позволил бы сохранить половину настроек, забыв
     * другую.
     */
    public function saveReferral(bool $enabled, int $inviterBonus, int $invitedBonus): void
    {
        $this->writeValue(self::KEY_REFERRAL_ENABLED, $enabled);
        $this->writeValue(self::KEY_REFERRAL_INVITER_BONUS, ReferralPolicy::normalize($inviterBonus));
        $this->writeValue(self::KEY_REFERRAL_INVITED_BONUS, ReferralPolicy::normalize($invitedBonus));
    }

    /**
     * Награда за приглашение по текущим настройкам — решение принимает
     * `ReferralPolicy` (потолок, приведение негатива к нулю), а не читающий
     * настройки код: иначе «максимум награды» существовал бы в двух местах.
     */
    public function referralReward(): ReferralReward
    {
        $settings = $this->forAdmin();

        return ReferralPolicy::reward(
            $settings['referral_enabled'],
            $settings['referral_inviter_bonus'],
            $settings['referral_invited_bonus'],
        );
    }

    /**
     * На сколько часов раньше публичного старта продаж может покупать этот
     * пользователь. 0 — ранний доступ не положен: гость, выключенная настройка,
     * уровень ниже порога или уровень из настройки не найден.
     *
     * Возвращает ЧАСЫ, а не bool: `Session::isSellableAt()` получив их, сам
     * решает про окно — правило «когда продаём» остаётся в одном месте.
     */
    public function earlyAccessHoursForUser(?User $user): int
    {
        // Гостю ранний доступ не нужен: уровень клуба держится на аккаунте.
        if ($user === null) {
            return 0;
        }

        $settings = $this->forAdmin();

        if (!$settings['enabled']) {
            return 0;
        }

        $threshold = null;

        foreach ($settings['levels'] as $level) {
            if ($level['slug'] === $settings['min_level_slug']) {
                $threshold = (int) $level['min_paid_orders'];

                break;
            }
        }

        if ($threshold === null) {
            return 0;
        }

        return $this->levels->paidOrdersCount($user) >= $threshold
            ? $settings['hours']
            : 0;
    }

    /** @return array<string, mixed> сырые значения из БД */
    private function storedValues(): array
    {
        return $this->store->values(self::SCOPE);
    }

    private function writeValue(string $key, mixed $value): void
    {
        $this->store->write(self::SCOPE, $key, $value);
    }

    /**
     * Привести окно раннего доступа к [1, MAX_HOURS] — единственный владелец правила.
     *
     * Раньше `max(1, min(self::MAX_HOURS, $hours))` стоял дважды: в `forAdmin()`
     * (защита от испорченного значения в БД) и в `save()` (защита от прямого вызова
     * в обход HTTP-валидации: сид, миграция, команда). Формула одна — вызов один.
     */
    private function clampHours(int $hours): int
    {
        return max(1, min(self::MAX_HOURS, $hours));
    }
}
