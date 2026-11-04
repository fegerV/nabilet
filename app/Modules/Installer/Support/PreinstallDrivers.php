<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Support;

use Illuminate\Support\Facades\Log;

/**
 * Рабочие драйверы сессий/кеша на окно выполнения визарда.
 *
 * ЯЙЦО И КУРИЦА №3 (найдено 2026-11-04 тем же прогоном визарда на свежей
 * копии кода): дефолты Laravel без `.env` — `SESSION_DRIVER=database`
 * (таблица `web_sessions`) и `CACHE_STORE=database` (эту базу использует и
 * `throttle:5,1` на POST /install). Хост и порт БД на этом этапе ещё
 * НЕИЗВЕСТНЫ — их и должен назвать оператор на шаге 2 мастера, — поэтому
 * каждый запрос к ним падал `SQLSTATE[HY000] [2002]` и `/install` оставался
 * 500 даже с уже гарантированным ключом (`PreinstallAppKey`).
 *
 * РЕШЕНИЕ: пока `.env` не существует, сессии и кеш переключаем на файловые
 * драйверы (каталог `storage/` — в списке проверяемых требований мастера, на
 * шаред-хостинге он работать умеет). Директории хранилищ создаются явно:
 * расчёт на «потом само mkdir» здесь — верный 500 на первом же запросе.
 *
 * ГРАНИЦА: гейт — ОТСУТСТВИЕ `.env`, а НЕ `install.lock`. Установка пишет
 * файл первым шагом; «упавшая половиной» установка тоже оставляет реальный
 * `.env`, и дальше отвечают выбранные в нём драйверы, а их доступность
 * ловит мониторинг. Лок на `install.lock` здесь ОПАСЕН: в dev-репозитории
 * и в тестах его нет ВЕЧНО — и подмена session/cache=file включалась бы на
 * каждом буте (2026-11-04: разом упали ~50 phpunit-тестов, ожидающих
 * драйверы `database`). Строгий enforce после установки отвечает
 * `ProductionSafetyProvider` (гейт по `install.lock` у него — по назначению).
 */
final class PreinstallDrivers
{
    /**
     * Применить фолбэки (вызывается на буте).
     *
     * @param  string|null  $envPath  Подмена пути `.env` для тестов: вместо
     *                                    завязки на реальный файл окружения.
     */
    public static function apply(?string $envPath = null): void
    {
        $envPath ??= base_path('.env');

        if (is_file($envPath)) {
            return;
        }

        foreach ([storage_path('framework/sessions'), storage_path('framework/cache/data')] as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
                Log::warning('Installer: cannot create a pre-install storage directory', [
                    'path' => $directory,
                ]);
            }
        }

        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
        ]);
    }
}
