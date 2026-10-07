<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Настройки исходящей почты, редактируемые из админки.
 *
 * SMTP-параметры живут в таблице `settings` (scope=`mail`), а не в .env, потому
 * что менять их должен администратор магазина, а не тот, у кого есть SSH. До
 * этого письма нельзя было включить без правки .env и перезапуска — на
 * шаред-хостинге TimeWeb это отдельная задача для владельца.
 *
 * ПАРОЛЬ ХРАНИТСЯ ЗАШИФРОВАННЫМ (`encrypted = 1`, Crypt), и НИКОГДА не
 * возвращается в API: наружу уходит только флаг «пароль задан» и его длина.
 * Иначе любой, кто откроет DevTools в админке, получил бы доступ к SMTP-ящику,
 * а он часто совпадает с корпоративной почтой.
 *
 * Значения из БД перекрывают config/env. Это осознанно: .env — значение по
 * умолчанию для свежей установки, а таблица — то, что администратор настроил
 * осознанно, и его выбор должен побеждать.
 *
 * `applyRuntimeConfig()` вызывается на каждом запросе (см.
 * NotificationsServiceProvider) — почта уходит и из очереди, и из веб-запроса,
 * и оба пути обязаны видеть одни и те же настройки.
 */
class MailSettings
{
    public const SCOPE = 'mail';

    /** Ключи, которые администратор может править через UI. */
    public const EDITABLE = [
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
    ];

    /** Секрет: пишется в `settings.encrypted = 1`, наружу не отдаётся. */
    public const SECRET_KEYS = ['password'];

    /**
     * Загрузить настройки из БД и применить их к runtime-конфигу mail.
     *
     * Тихая деградация при недоступной таблице: на свежей установке (до
     * миграций) `settings` может не быть, и падать на этом нельзя — иначе
     * сломается и логин, и весь бутстрап.
     */
    public function applyRuntimeConfig(): void
    {
        $values = $this->storedValues();

        if ($values === []) {
            return;
        }

        $host = $values['host'] ?? null;

        // Пока хост не задан, mailer остаётся тем, что в .env: переключение на
        // smtp с пустым хостом превратило бы весь сайт в «почта не уходит».
        if (! is_string($host) || trim($host) === '') {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', (int) ($values['port'] ?? 587));

        $username = $values['username'] ?? null;
        if (is_string($username) && $username !== '') {
            Config::set('mail.mailers.smtp.username', $username);
        }

        $password = $this->decryptedPassword($values);
        if ($password !== null) {
            Config::set('mail.mailers.smtp.password', $password);
        }

        // Laravel 11+ ждёт `encryption` как `scheme` в mailers.smtp: `tls` ↔
        // connect with STARTTLS, `ssl` ↔ implicit TLS. Пустая строка = без шифрования.
        $scheme = match ((string) ($values['encryption'] ?? '')) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            default => null,
        };

        if ($scheme !== null) {
            Config::set('mail.mailers.smtp.scheme', $scheme);
        }

        $fromAddress = $values['from_address'] ?? null;
        if (is_string($fromAddress) && $fromAddress !== '') {
            Config::set('mail.from.address', $fromAddress);
        }

        $fromName = $values['from_name'] ?? null;
        if (is_string($fromName) && $fromName !== '') {
            Config::set('mail.from.name', $fromName);
        }
    }

    /**
     * Настройки для формы в админке. Пароль НЕ возвращается — только признак,
     * что он задан. Иначе админка стала бы способом выгрузить SMTP-секрет.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(): array
    {
        $values = $this->storedValues();
        $password = $this->decryptedPassword($values);

        return [
            'host' => (string) ($values['host'] ?? ''),
            'port' => (int) ($values['port'] ?? 587),
            'username' => (string) ($values['username'] ?? ''),
            'password_set' => $password !== null && $password !== '',
            'encryption' => (string) ($values['encryption'] ?? 'tls'),
            'from_address' => (string) ($values['from_address'] ?? config('mail.from.address', '')),
            'from_name' => (string) ($values['from_name'] ?? config('mail.from.name', '')),
            // Что реально уйдёт в транспорт сейчас — чтобы админ видел эффект,
            // а не догадывался, применились ли правки.
            'effective_mailer' => (string) config('mail.default'),
            'effective_host' => (string) config('mail.mailers.smtp.host', ''),
        ];
    }

    /**
     * Сохранить настройки. `null` в значении удаляет переопределение (возврат к
     * .env); `password` со значением `null` НЕ удаляет пароль — для этого есть
     * отдельный флаг, иначе случайное сохранение формы без пароля стёрло бы его.
     *
     * @param  array<string, mixed>  $values
     * @param  bool  $clearPassword
     */
    public function save(array $values, bool $clearPassword = false): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::EDITABLE, true)) {
                continue;
            }

            if ($key === 'password') {
                // Пустая строка = «не менять пароль», а не «стереть».
                if (is_string($value) && $value !== '') {
                    $this->writeValue('password', $value, encrypted: true);
                }

                continue;
            }

            if ($value === null) {
                $this->forget($key);

                continue;
            }

            $this->writeValue($key, $value);
        }

        if ($clearPassword) {
            $this->forget('password');
        }

        // Применить сразу: админ сохранил — письмо должно уйти уже с новыми
        // настройками, без перезапуска воркера.
        $this->applyRuntimeConfig();
    }

    /**
     * Отправить тестовое письмо текущими настройками.
     *
     * Возвращает текст ошибки или null при успехе — админке нужен внятный
     * ответ, а не 500, потому что «неверный пароль SMTP» это ожидаемая
     * ситуация при настройке, а не сбой сервера.
     */
    public function sendTest(string $recipient): ?string
    {
        // Настройки могли поменяться в этом же запросе — берём актуальные.
        $this->applyRuntimeConfig();

        try {
            Mail::raw(
                'Это тестовое письмо от NABILET. Настройки SMTP применились успешно.',
                static function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('NABILET: проверка SMTP');
                },
            );

            return null;
        } catch (Throwable $e) {
            return str_ireplace($recipient, '[адрес скрыт]', $e->getMessage());
        }
    }

    /** @return array<string, mixed> сырые значения из БД (пароль — всё ещё шифротекст) */
    private function storedValues(): array
    {
        try {
            $rows = Setting::query()
                ->where('scope', self::SCOPE)
                ->pluck('value_json', 'setting_key')
                ->all();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $key => $value) {
            $decoded = json_decode((string) $value, true);
            $out[$key] = $decoded === null ? $value : $decoded;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function decryptedPassword(array $values): ?string
    {
        $raw = $values['password'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (Throwable) {
            // Нечитаемый шифротекст (сменился APP_KEY) — не валим запрос:
            // трактуем как «пароль не задан», админ введёт заново.
            return null;
        }
    }

    private function writeValue(string $key, mixed $value, bool $encrypted = false): void
    {
        $stored = $encrypted ? Crypt::encryptString((string) $value) : $value;

        // updateOrCreate кладёт второй массив только в UPDATE. На INSERT он бы
        // потерял `updated_at`, а колонка NOT NULL без дефолта — поэтому
        // выставляем метку через модель явно, до сохранения.
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
