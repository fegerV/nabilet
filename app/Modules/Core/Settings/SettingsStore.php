<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Settings;

use Illuminate\Support\Facades\Crypt;
use Nabilet\Modules\Core\Models\Setting;
use Throwable;

/**
 * Чтение и запись настроек из таблицы `settings` — один владелец правила.
 *
 * ЗАЧЕМ. Три сервиса настроек (`PaymentSettings`, `MailSettings`,
 * `MatrixSettings`) держали побайтово одинаковые приватные методы:
 *
 *   values()/storedValues()   выборка по scope + json_decode каждой строки
 *   writeValue()              шифрование секрета, firstOrNew, явный updated_at
 *   forget()                  удаление строки по scope+ключу
 *   decrypt()                 Crypt::decryptString с «нечитаемый = не задан»
 *
 * Это не косметика. Правило здесь одно и оно неочевидное: у `settings` НЕТ
 * колонки `created_at` (только `updated_at`, NOT NULL без дефолта), поэтому
 * `updateOrCreate()` терял метку на INSERT и падал. Это было выяснено и
 * записано в комментариях каждого из трёх файлов — то есть знание жило в трёх
 * копиях, и четвёртый сервис настроек завёл бы четвёртую.
 *
 * ПОЧЕМУ НЕ В `app/Core`. Там framework-agnostic ядро, и ни один файл оттуда не
 * импортирует `Nabilet\Modules\...` (см. `tools/verify-module-structure.php`).
 * Этот класс работает с Eloquent-моделью `Setting`, поэтому его место — рядом
 * с моделью, в `Modules/Core`.
 *
 * КЭШ. `MatrixSettings` читает настройки на каждый вызов аксессора (в том числе
 * в цикле по заказам), поэтому ему нужен кэш в пределах запроса. Кэш здесь
 * опционален и включается флагом: `PaymentSettings` и `MailSettings` читают
 * редко и обязаны видеть изменения, сделанные другим процессом, — им кэш
 * включать нельзя.
 */
final class SettingsStore
{
    /** @var array<string, array<string, mixed>> scope => значения */
    private array $cache = [];

    public function __construct(private readonly bool $cached = false) {}

    /**
     * Сырые значения области: ключ → раскодированный `value_json`.
     *
     * Секреты возвращаются ШИФРОТЕКСТОМ — расшифровка остаётся на совести
     * вызывающего, потому что не всем полям нужен открытый текст.
     *
     * Ошибку БД глушим намеренно: настройки читаются на бутстрапе, и падение
     * здесь уронило бы всё приложение из-за недоступной таблицы. Пустой набор
     * означает «настройки не заданы», и код откатывается на config.
     *
     * @return array<string, mixed>
     */
    public function values(string $scope): array
    {
        if ($this->cached && array_key_exists($scope, $this->cache)) {
            return $this->cache[$scope];
        }

        try {
            $rows = Setting::query()
                ->where('scope', $scope)
                ->pluck('value_json', 'setting_key')
                ->all();
        } catch (Throwable) {
            return $this->cached ? ($this->cache[$scope] = []) : [];
        }

        $out = [];

        foreach ($rows as $key => $value) {
            $decoded = json_decode((string) $value, true);
            $out[$key] = $decoded === null ? $value : $decoded;
        }

        return $this->cached ? ($this->cache[$scope] = $out) : $out;
    }

    /**
     * Записать значение. `$encrypted` шифрует его перед сохранением.
     *
     * Метка `updated_at` выставляется ЯВНО, до `save()`: у таблицы нет
     * `created_at`, `updateOrCreate()` на INSERT потерял бы метку, а колонка
     * NOT NULL без дефолта — то есть вставка упала бы.
     */
    public function write(string $scope, string $key, mixed $value, bool $encrypted = false): void
    {
        $stored = $encrypted ? Crypt::encryptString((string) $value) : $value;

        $setting = Setting::query()->firstOrNew([
            'scope' => $scope,
            'setting_key' => $key,
        ]);

        $setting->value_json = json_encode($stored, JSON_UNESCAPED_UNICODE);
        $setting->encrypted = $encrypted;
        $setting->updated_at = now();
        $setting->save();
    }

    /** Удалить значение — «не задано», тогда вызывающий возьмёт config. */
    public function forget(string $scope, string $key): void
    {
        Setting::query()
            ->where('scope', $scope)
            ->where('setting_key', $key)
            ->delete();
    }

    /**
     * Сбросить кэш. Обязательно после записи: иначе аксессоры вернут старое.
     */
    public function invalidate(): void
    {
        $this->cache = [];
    }

    /**
     * Расшифровать секрет.
     *
     * Нечитаемый шифротекст (сменился `APP_KEY`) — не ошибка запроса, а
     * «секрет не задан»: администратор введёт его заново. Валить из-за этого
     * страницу настроек или приём платежа нельзя.
     */
    public static function decrypt(string $cipherText): ?string
    {
        if ($cipherText === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cipherText);
        } catch (Throwable) {
            return null;
        }
    }
}
