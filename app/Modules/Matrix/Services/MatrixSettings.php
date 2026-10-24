<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Services;

use Illuminate\Support\Facades\Config;
use Nabilet\Modules\Core\Settings\SettingsStore;

/**
 * Настройки канала Matrix, редактируемые из админки.
 *
 * ПОЧЕМУ ТАБЛИЦА `settings`, А НЕ `.env`
 *   Адрес homeserver'а, идентификатор комнаты и токен доступа к Synapse —
 *   параметры установки, а не кода. Тот, кто ведёт магазин, не имеет доступа к
 *   `.env` на шаред-хостинге, а без этих трёх значений канал не включить. Тот
 *   же механизм, что у SMTP (`MailSettings`, scope=`mail`) и платёжных ключей
 *   (`PaymentSettings`, scope=`payment`): значения в таблице `settings`
 *   (scope=`matrix`) перекрывают `.env`, а ключ, которого в таблице нет,
 *   конфиг не трогает — поведение свежей установки ровно как было.
 *
 * ТОКЕН ХРАНИТСЯ ЗАШИФРОВАННЫМ И НЕ ВОЗВРАЩАЕТСЯ
 *   `access_token` Synapse даёт полный доступ к аккаунту бота: с ним можно
 *   читать комнаты, писать от его имени и создавать новые. Он пишется с
 *   `encrypted = 1` (`Crypt`), а наружу уходит только признак «задан». Иначе
 *   DevTools в админке отдавал бы токен, а он не отзывается вместе с сессией —
 *   утечка была бы долгой. Пустая строка в форме = «не менять»; стереть —
 *   только явным флагом, чтобы обычное сохранение формы не рвало работающий
 *   канал.
 *
 * ЧТЕНИЕ ЗНАЧЕНИЙ — БЕЗ ЗАВИСИМОСТИ ОТ ПОРЯДКА БУТСТРАПА
 *   Аксессоры (`homeserverUrl()`, `accessToken()`, …) читают БД сами и падают
 *   на `.env`-значение из `config`, если записи нет. Это важно для воркера
 *   очереди: джоба отправки резолвит сервис из контейнера в своём процессе, и
 *   она обязана увидеть те же настройки, что и админка, независимо от того,
 *   успел ли `applyRuntimeConfig()` выполниться. Значения кэшируются на время
 *   жизни сервиса (он синглтон) — `save()` кэш сбрасывает.
 *
 * Тихая деградация при недоступной таблице: на свежей установке (до миграций)
 * `settings` может не быть, и падать на этом нельзя — иначе не поднимется даже
 * логин.
 */
final class MatrixSettings
{
    public const SCOPE = 'matrix';

    public const KEY_HOMESERVER = 'homeserver_url';
    public const KEY_ROOM_ID = 'room_id';
    public const KEY_ACCESS_TOKEN = 'access_token';
    public const KEY_ENABLED = 'enabled';
    public const KEY_NOTIFY_ORDERS = 'notify_orders';

    /** Ключи, которые администратор может править через UI. */
    public const EDITABLE = [
        self::KEY_HOMESERVER,
        self::KEY_ROOM_ID,
        self::KEY_ACCESS_TOKEN,
        self::KEY_ENABLED,
        self::KEY_NOTIFY_ORDERS,
    ];

    /** Секреты: пишутся с `encrypted = 1`, наружу не отдаются. */
    public const SECRET_KEYS = [
        self::KEY_ACCESS_TOKEN,
    ];

    /** Пути в `config/nabilet.php` — фолбэк, когда в таблице записи нет. */
    private const CONFIG_HOMESERVER = 'nabilet.matrix.homeserver_url';
    private const CONFIG_ROOM_ID = 'nabilet.matrix.room_id';
    private const CONFIG_ACCESS_TOKEN = 'nabilet.matrix.access_token';
    private const CONFIG_ENABLED = 'nabilet.matrix.enabled';
    private const CONFIG_NOTIFY_ORDERS = 'nabilet.matrix.notify_orders';

    /**
     * Чтение/запись `settings` С кэшем: аксессоры (`isEnabled()`,
     * `notifyOrders()`) зовутся на каждый заказ, а таблица — одна на всю
     * установку. Кэш сбрасывается в `save()` через `invalidate()`.
     */
    private SettingsStore $store;

    public function __construct()
    {
        $this->store = new SettingsStore(cached: true);
    }

    /**
     * Применить настройки из БД к runtime-конфигу.
     *
     * Нужно для наблюдаемости (`artisan config:show nabilet.matrix`) и чтобы
     * боевые значения были видны там же, где остальные настройки установки.
     * Вызывается на бутстрапе (см. `MatrixServiceProvider::boot()`) и после
     * каждого сохранения в админке.
     */
    public function applyRuntimeConfig(): void
    {
        $values = $this->values();

        if (array_key_exists(self::KEY_HOMESERVER, $values)) {
            Config::set(self::CONFIG_HOMESERVER, (string) $values[self::KEY_HOMESERVER]);
        }

        if (array_key_exists(self::KEY_ROOM_ID, $values)) {
            Config::set(self::CONFIG_ROOM_ID, (string) $values[self::KEY_ROOM_ID]);
        }

        if (array_key_exists(self::KEY_ACCESS_TOKEN, $values)) {
            $token = $this->decrypt((string) $values[self::KEY_ACCESS_TOKEN]);

            if ($token !== null) {
                Config::set(self::CONFIG_ACCESS_TOKEN, $token);
            }
        }

        if (array_key_exists(self::KEY_ENABLED, $values)) {
            Config::set(self::CONFIG_ENABLED, (bool) $values[self::KEY_ENABLED]);
        }

        if (array_key_exists(self::KEY_NOTIFY_ORDERS, $values)) {
            Config::set(self::CONFIG_NOTIFY_ORDERS, (bool) $values[self::KEY_NOTIFY_ORDERS]);
        }
    }

    public function homeserverUrl(): string
    {
        return $this->string(self::KEY_HOMESERVER, self::CONFIG_HOMESERVER);
    }

    public function roomId(): string
    {
        return $this->string(self::KEY_ROOM_ID, self::CONFIG_ROOM_ID);
    }

    public function accessToken(): string
    {
        $values = $this->values();

        if (array_key_exists(self::KEY_ACCESS_TOKEN, $values)) {
            return (string) ($this->decrypt((string) $values[self::KEY_ACCESS_TOKEN]) ?? '');
        }

        return trim((string) config(self::CONFIG_ACCESS_TOKEN, ''));
    }

    public function isEnabled(): bool
    {
        return $this->bool(self::KEY_ENABLED, self::CONFIG_ENABLED);
    }

    public function notifyOrders(): bool
    {
        return $this->bool(self::KEY_NOTIFY_ORDERS, self::CONFIG_NOTIFY_ORDERS);
    }

    /** Заполнены ли все три обязательных значения — без этого отправка невозможна. */
    public function isConfigured(): bool
    {
        return $this->homeserverUrl() !== ''
            && $this->roomId() !== ''
            && $this->accessToken() !== '';
    }

    /**
     * Настройки для формы в админке.
     *
     * `*_from_env` — признак «значение пришло из .env, в таблице записи нет»:
     * форма показывает это бейджем, чтобы владелец понимал, что правит он env
     * или БД. Токен наружу не отдаётся — только признак «задан».
     *
     * @return array<string, mixed>
     */
    public function forAdmin(): array
    {
        $values = $this->values();

        return [
            'homeserver_url' => $this->homeserverUrl(),
            'homeserver_url_from_env' => ! array_key_exists(self::KEY_HOMESERVER, $values),
            'room_id' => $this->roomId(),
            'room_id_from_env' => ! array_key_exists(self::KEY_ROOM_ID, $values),
            'access_token_set' => $this->accessToken() !== '',
            'enabled' => $this->isEnabled(),
            'enabled_from_env' => ! array_key_exists(self::KEY_ENABLED, $values),
            'notify_orders' => $this->notifyOrders(),
            'notify_orders_from_env' => ! array_key_exists(self::KEY_NOTIFY_ORDERS, $values),
            // Что реально уйдёт в транспорт сейчас — чтобы админ видел эффект,
            // а не догадывался, применились ли правки.
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * Сохранить настройки из админки.
     *
     * ПОЧЕМУ `null` ЗНАЧИТ «СТЕРЕТЬ», А НЕ «НЕ МЕНЯТЬ»
     *   Laravel'овский `ConvertEmptyStringsToNull` превращает пустое поле формы
     *   в `null` ещё до валидации. Если трактовать `null` как «не менять»,
     *   очистить адрес или комнату из формы стало бы НЕВОЗМОЖНО: администратор
     *   стирает поле, сохраняет — и видит прежнее значение. Поэтому `null`
     *   удаляет переопределение и возвращает значение из `.env` (тот же выбор,
     *   что у SMTP в `MailSettings`). Отсутствующий в запросе ключ сюда вообще
     *   не доходит — `foreach` идёт по присланным полям.
     *
     * ПОЧЕМУ У СЕКРЕТА ПРАВИЛО ОБРАТНОЕ
     *   Пустое поле токена приходит тем же `null` — и это «не менять»:
     *   форма не присылает токен никогда (он не отдаётся наружу), поэтому
     *   «пусто» здесь означает «оставить как есть», а не «стереть». Стереть —
     *   только явным флагом `$clearToken`.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values, bool $clearToken = false): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::EDITABLE, true)) {
                continue;
            }

            if (in_array($key, self::SECRET_KEYS, true)) {
                if (is_string($value) && $value !== '') {
                    $this->writeValue($key, $value, encrypted: true);
                }

                continue;
            }

            if ($value === null) {
                $this->forget($key);

                continue;
            }

            if (in_array($key, [self::KEY_ENABLED, self::KEY_NOTIFY_ORDERS], true)) {
                $this->writeValue($key, (bool) $value);

                continue;
            }

            $this->writeValue($key, (string) $value);
        }

        if ($clearToken) {
            $this->forget(self::KEY_ACCESS_TOKEN);
        }

        // Сбросить кэш ДО применения: иначе аксессоры вернули бы старые значения.
        $this->store->invalidate();

        // Применить сразу: администратор сохранил — тест-отправка должна уйти
        // уже с новыми настройками, без перезапуска воркера.
        $this->applyRuntimeConfig();
    }

    /**
     * @return array<string, mixed> сырые значения из БД (токен — всё ещё шифротекст)
     */
    private function values(): array
    {
        return $this->store->values(self::SCOPE);
    }

    private function string(string $key, string $configPath): string
    {
        $values = $this->values();

        if (array_key_exists($key, $values)) {
            return is_scalar($values[$key]) ? trim((string) $values[$key]) : '';
        }

        return trim((string) config($configPath, ''));
    }

    private function bool(string $key, string $configPath): bool
    {
        $values = $this->values();

        if (array_key_exists($key, $values)) {
            return (bool) $values[$key];
        }

        return (bool) config($configPath, false);
    }

    private function decrypt(string $cipherText): ?string
    {
        return SettingsStore::decrypt($cipherText);
    }

    private function writeValue(string $key, mixed $value, bool $encrypted = false): void
    {
        $this->store->write(self::SCOPE, $key, $value, $encrypted);
    }

    private function forget(string $key): void
    {
        $this->store->forget(self::SCOPE, $key);
    }
}
