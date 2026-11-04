<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Nabilet\Modules\Installer\Support\PreinstallDrivers;
use Tests\TestCase;

/**
 * Фолбэки драйверов сессий/кеша на окно визарда.
 *
 * Контекст: дефолты Laravel без `.env` — `SESSION_DRIVER=database`
 * (таблица `web_sessions`) и `CACHE_STORE=database` (её же использует
 * `throttle` на POST /install) — до ввода хоста БД физически недостижимы,
 * `/install` отвечал 500 `SQLSTATE[HY000] [2002]`. Класс подменяет оба
 * драйвера на файловые ТОЛЬКО пока `storage/install.lock` не существует.
 */
class PreinstallDriversTest extends TestCase
{
    /** Путь-подделка: файла не существует → фолбэки включаются. */
    private function absentEnvPath(): string
    {
        return sys_get_temp_dir() . '/nabilet-absent-env-' . uniqid() . '/.env';
    }

    public function test_preinstall_requests_are_switched_to_file_backed_drivers(): void
    {
        PreinstallDrivers::apply($this->absentEnvPath());

        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
    }

    public function test_dirs_for_file_backed_stores_exist_after_apply(): void
    {
        // Каталоги должны существовать после apply() вне зависимости от того,
        // были ли они созданы заранее: обработчик файловых сессий и кеш не
        // должны полагаться на «потом само mkdir».
        PreinstallDrivers::apply($this->absentEnvPath());

        $this->assertDirectoryExists(storage_path('framework/sessions'));
        $this->assertDirectoryExists(storage_path('framework/cache/data'));
    }

    /**
     * Когда `.env` ЕСТЬ, подмены не происходит: выбранная конфигурация
     * отвечает сама (это и ловит regression 2026-11-04 — гейт по install.lock
     * включал подмену на КАЖДОМ буте, где лока нет, и ронял ~50 тестов).
     */
    public function test_an_existing_env_file_disables_the_substitution(): void
    {
        $before = [config('session.driver'), config('cache.default')];

        PreinstallDrivers::apply(base_path('.env'));

        $this->assertSame($before, [config('session.driver'), config('cache.default')]);
    }
}
