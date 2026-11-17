<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Installer\Support\PreinstallAppKey;
use Nabilet\Modules\Installer\Support\PreinstallDrivers;
use Nabilet\Modules\Installer\Support\SetupArtifactCleaner;

class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind installer services
    }

    public function boot(): void
    {
        // Routes are loaded by routes/api.php (it requires this module's web.php
        // first, so the installer is reachable before any other module). Loading
        // them again here would double-register the named routes, so we only mount
        // the views here.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'installer');

        // Яйцо и курица №2 на свежем шаред-хостинге: до записи `.env` APP_KEY
        // не существует, но роуты инсталлера живут в группе `web`, где
        // `EncryptCookies` резолвит encrypter на этапе dispatch — визард был
        // недоступен (`GET /install` → 500 MissingAppKeyException) ещё до
        // того, как могла открыться форма, которая этот ключ И генерирует.
        // Подробности и граница «после установки — ключ только из .env»
        // в классе `PreinstallAppKey`.
        PreinstallAppKey::apply();

        // Яйцо и курица №3, тем же прогоном: дефолты Laravel без `.env` —
        // `SESSION_DRIVER=database` (таблица `web_sessions`) и
        // `CACHE_STORE=database` (её же использует `throttle:5,1` на
        // POST /install) — до ввода хоста БД физически недостижимы. Границы
        // подмены и каталоги хранилищ — в классе `PreinstallDrivers`.
        PreinstallDrivers::apply();

        // УЖЕ УСТАНОВЛЕННЫЙ САЙТ: свежий zip заливается поверх существующей
        // инсталляции, поэтому `markAsInstalled()` не вызывается, а info.php
        // и legacy-установщик остаются в docroot. Чистим один раз на версию
        // приложения — обновление версии в config/nabilet.php повторяет проверку.
        if (is_file($this->app->storagePath('install.lock'))) {
            try {
                $removed = SetupArtifactCleaner::make()
                    ->purgeOnce((string) config('nabilet.version'));
                if ($removed !== []) {
                    Log::info('Installer: удалены файлы развёртывания после выкладки', [
                        'paths' => $removed,
                    ]);
                }
            } catch (\Throwable $e) {
                // Защита от сбоя прав доступа: сайт должен подняться, даже если
                // удалить диагностический файл не удалось.
                Log::warning('Installer: не удалась пост-выкладочная зачистка', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
