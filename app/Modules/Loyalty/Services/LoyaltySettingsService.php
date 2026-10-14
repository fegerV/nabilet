<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use App\Models\Setting;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Настройки «раннего доступа» клуба («Клуб зрителей», фаза 3b).
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

    /** Дефолты: выключено, сутки, «друг» — тот же порог, что и у бонусов. */
    public const DEFAULT_HOURS = 24;
    public const DEFAULT_MIN_LEVEL_SLUG = 'friend';
    /** Верхний предел N часов: 30 суток. Дальше — почти «всегда», смысла нет. */
    public const MAX_HOURS = 720;

    public function __construct(
        private readonly LoyaltyLevelService $levels = new LoyaltyLevelService(),
    ) {
    }

    /**
     * Настройки для формы админки: значения плюс список уровней — выпадающий
     * список порога собирается из тех же данных, по которым считается уровень.
     *
     * @return array{
     *     enabled: bool,
     *     hours: int,
     *     min_level_slug: string,
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
            'hours' => max(1, min(self::MAX_HOURS, $hours)),
            'min_level_slug' => (string) ($values[self::KEY_MIN_LEVEL_SLUG] ?? self::DEFAULT_MIN_LEVEL_SLUG),
            'levels' => $this->levels->tiers(),
        ];
    }

    /**
     * Сохранить настройки из админки. Валидация — за контроллером; здесь только
     * нормализация часов в допустимый диапазон.
     */
    public function save(bool $enabled, int $hours, string $minLevelSlug): void
    {
        $this->writeValue(self::KEY_ENABLED, $enabled);
        $this->writeValue(self::KEY_HOURS, max(1, min(self::MAX_HOURS, $hours)));
        $this->writeValue(self::KEY_MIN_LEVEL_SLUG, $minLevelSlug);
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
        try {
            $rows = Setting::query()
                ->where('scope', self::SCOPE)
                ->pluck('value_json', 'setting_key')
                ->all();
        } catch (\Throwable) {
            // Свежая установка до миграций: настроек нет — работаем по дефолтам.
            return [];
        }

        $out = [];

        foreach ($rows as $key => $value) {
            $decoded = json_decode((string) $value, true);
            $out[$key] = $decoded === null ? $value : $decoded;
        }

        return $out;
    }

    private function writeValue(string $key, mixed $value): void
    {
        // Таблица `settings` несёт только `updated_at` (NOT NULL, без дефолта) —
        // метку выставляем явно, как в `MailSettings`.
        $setting = Setting::query()->firstOrNew([
            'scope' => self::SCOPE,
            'setting_key' => $key,
        ]);

        $setting->value_json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $setting->encrypted = false;
        $setting->updated_at = now();
        $setting->save();
    }
}
