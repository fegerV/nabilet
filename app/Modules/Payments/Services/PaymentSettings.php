<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/**
 * Настройки платёжных провайдеров, редактируемые из админки.
 *
 * ПОЧЕМУ ТАБЛИЦА `settings`, А НЕ `.env`
 *   Ключи ЮKassa (shop_id, secret_key, webhook_secret) сейчас живут только в
 *   `.env`. На шаред-хостинге владелец магазина не имеет к нему доступа, и
 *   включить приём платежей можно было только через поддержку хостинга. Здесь —
 *   тот же механизм, что и у SMTP (`MailSettings`, scope=`mail`): значения в
 *   таблице `settings` (scope=`payment`), применяются к runtime-конфигу на
 *   каждом бутстрапе и перекрывают `.env`.
 *
 * ЗНАЧЕНИЯ ИЗ БД ПОБЕЖДАЮТ `.env`
 *   Осознанно: `.env` — значения свежей установки, таблица — то, что владелец
 *   настроил руками. Ключ, которого в таблице нет, не трогает конфиг: пока
 *   настройка не сохранена, поведение ровно как было.
 *
 * СЕКРЕТЫ НЕ ВОЗВРАЩАЮТСЯ
 *   secret_key и webhook_secret хранятся зашифрованными (`encrypted = 1`,
 *   Crypt), а наружу уходит только признак «задан». Иначе DevTools в админке
 *   отдавали бы ключи приёма платежей. Пустая строка в форме = «не менять»
 *   (как пароль SMTP); стереть — явный флаг.
 *
 * РЕЕСТР ПРОВАЙДЕРОВ
 *   Архитектура на ≥2 провайдеров уже есть (`PaymentProviderRegistry`), и
 *   настройка «провайдер по умолчанию» валидируется по реестру, а не по
 *   зашитому `in:`. Сейчас в реестре один провайдер — ЮKassa; Сбербанк и прочие
 *   подключаются регистрацией в реестре, без правок этого класса.
 */
final class PaymentSettings
{
    public const SCOPE = 'payment';

    public const KEY_SHOP_ID = 'yookassa_shop_id';
    public const KEY_SECRET_KEY = 'yookassa_secret_key';
    public const KEY_WEBHOOK_SECRET = 'yookassa_webhook_secret';
    public const KEY_DEMO_MODE = 'yookassa_demo_mode';
    public const KEY_DEFAULT_PROVIDER = 'default_provider';

    /** Ключи, которые администратор может править через UI. */
    public const EDITABLE = [
        self::KEY_SHOP_ID,
        self::KEY_SECRET_KEY,
        self::KEY_WEBHOOK_SECRET,
        self::KEY_DEMO_MODE,
        self::KEY_DEFAULT_PROVIDER,
    ];

    /** Секреты: пишутся с `encrypted = 1`, наружу не отдаются. */
    public const SECRET_KEYS = [
        self::KEY_SECRET_KEY,
        self::KEY_WEBHOOK_SECRET,
    ];

    /**
     * Применить настройки из БД к runtime-конфигу платежей.
     *
     * Тихая деградация при недоступной таблице (свежая установка до миграций) —
     * как в `MailSettings`: падать на бутстрапе нельзя, иначе не поднимется даже
     * логин. Вызывается на каждом бутстрапе (см. `PaymentServiceProvider::boot()`)
     * и после каждого сохранения в админке.
     */
    public function applyRuntimeConfig(): void
    {
        $values = $this->storedValues();

        if (array_key_exists(self::KEY_SHOP_ID, $values)) {
            Config::set('nabilet.payment.yookassa.shop_id', (string) $values[self::KEY_SHOP_ID]);
        }

        if (array_key_exists(self::KEY_SECRET_KEY, $values)) {
            $secret = $this->decrypt((string) $values[self::KEY_SECRET_KEY]);

            if ($secret !== null) {
                Config::set('nabilet.payment.yookassa.secret_key', $secret);
            }
        }

        if (array_key_exists(self::KEY_WEBHOOK_SECRET, $values)) {
            $secret = $this->decrypt((string) $values[self::KEY_WEBHOOK_SECRET]);

            if ($secret !== null) {
                Config::set('nabilet.payment.yookassa.webhook_secret', $secret);
            }
        }

        if (array_key_exists(self::KEY_DEMO_MODE, $values)) {
            Config::set('nabilet.payment.demo_mode', (bool) $values[self::KEY_DEMO_MODE]);
        }

        if (array_key_exists(self::KEY_DEFAULT_PROVIDER, $values)) {
            Config::set('nabilet.payment.default_provider', (string) $values[self::KEY_DEFAULT_PROVIDER]);
        }
    }

    /**
     * Настройки для формы в админке.
     *
     * `*_env` — признак «значение пришло из .env, в таблице записи нет»: форма
     * показывает это бейджем, чтобы владелец понимал, что правит он env или БД.
     * Секреты наружу не отдаются — только признак «задан».
     *
     * @return array<string, mixed>
     */
    public function forAdmin(): array
    {
        $values = $this->storedValues();

        $shopIdInDb = array_key_exists(self::KEY_SHOP_ID, $values);
        $demoInDb = array_key_exists(self::KEY_DEMO_MODE, $values);
        $providerInDb = array_key_exists(self::KEY_DEFAULT_PROVIDER, $values);

        return [
            'shop_id' => $shopIdInDb
                ? (string) $values[self::KEY_SHOP_ID]
                : (string) config('nabilet.payment.yookassa.shop_id', ''),
            'shop_id_from_env' => ! $shopIdInDb,
            'secret_key_set' => $this->secretIsSet($values, self::KEY_SECRET_KEY, 'nabilet.payment.yookassa.secret_key'),
            'webhook_secret_set' => $this->secretIsSet($values, self::KEY_WEBHOOK_SECRET, 'nabilet.payment.yookassa.webhook_secret'),
            'demo_mode' => $demoInDb
                ? (bool) $values[self::KEY_DEMO_MODE]
                : (bool) config('nabilet.payment.demo_mode', false),
            'demo_mode_from_env' => ! $demoInDb,
            'default_provider' => $providerInDb
                ? (string) $values[self::KEY_DEFAULT_PROVIDER]
                : (string) config('nabilet.payment.default_provider', 'yookassa'),
            'default_provider_from_env' => ! $providerInDb,
        ];
    }

    /**
     * Сохранить настройки из админки.
     *
     * `null` в значении = не менять (поле не пришло); пустая строка у секрета =
     * «не менять» (стереть — только явным флагом); `$clearSecrets` удаляет
     * секреты. `shop_id` с пустой строкой пишется как пустая строка — это
     * осознанное «провайдер не настроен», и `YooKassaProvider` на этом поле
     * честно откажет (или уйдёт в demo-режим, если он включён).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $clearSecrets
     */
    public function save(array $values, array $clearSecrets = []): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::EDITABLE, true)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (in_array($key, self::SECRET_KEYS, true)) {
                // Пустая строка = «не менять», как пароль SMTP.
                if (is_string($value) && $value !== '') {
                    $this->writeValue($key, $value, encrypted: true);
                }

                continue;
            }

            $this->writeValue($key, $value);
        }

        foreach ($clearSecrets as $key) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                $this->forget($key);
            }
        }

        // Применить сразу: платеж после сохранения работает с новыми ключами,
        // без перезапуска воркера.
        $this->applyRuntimeConfig();
    }

    /** @return array<string, mixed> сырые значения из БД (секреты — шифротекст) */
    private function storedValues(): array
    {
        try {
            $rows = Setting::query()
                ->where('scope', self::SCOPE)
                ->pluck('value_json', 'setting_key')
                ->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($rows as $key => $value) {
            $decoded = json_decode((string) $value, true);
            $out[$key] = $decoded === null ? $value : $decoded;
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    private function secretIsSet(array $values, string $key, string $configPath): bool
    {
        if (array_key_exists($key, $values)) {
            $stored = $this->decrypt((string) $values[$key]);

            return $stored !== null && $stored !== '';
        }

        return (string) config($configPath, '') !== '';
    }

    private function decrypt(string $cipherText): ?string
    {
        if ($cipherText === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cipherText);
        } catch (\Throwable) {
            // Нечитаемый шифротекст (сменился APP_KEY) — не валим запрос:
            // трактуем как «не задан», владелец введёт заново.
            return null;
        }
    }

    private function writeValue(string $key, mixed $value, bool $encrypted = false): void
    {
        $stored = $encrypted ? Crypt::encryptString((string) $value) : $value;

        // Таблица `settings` несёт только `updated_at` (NOT NULL, без дефолта) —
        // метку выставляем явно, как в `MailSettings`.
        $setting = Setting::query()->firstOrNew([
            'scope' => self::SCOPE,
            'setting_key' => $key,
        ]);

        $setting->value_json = json_encode($stored, JSON_UNESCAPED_UNICODE);
        $setting->encrypted = $encrypted;
        $setting->updated_at = now();
        $setting->save();
    }

    private function forget(string $key): void
    {
        Setting::query()
            ->where('scope', self::SCOPE)
            ->where('setting_key', $key)
            ->delete();
    }
}
