<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Nabilet\Modules\System\Providers\ProductionSafetyProvider;
use Tests\TestCase;

/**
 * Гейт production-безопасности: когда проверки адресуются, а когда ждут.
 *
 * ДО ЭТОГО
 *   На свежем шаред-хостинге (без `.env`) Laravel подставляет дефолты:
 *   `APP_ENV=production`, `CACHE_STORE=database` (`config/cache.php`),
 *   `APP_URL=http://localhost`. Каждая из них «нарушает» правила
 *   `ProductionSafetyAudit`, провайдер бросал исключение на `boot()` — и
 *   **сам установщик был недоступен** (`/install` отвечал 500): конфигурация
 *   некорректна до того, как оператор хоть что-то ввёл, но исправить её
 *   через веб было невозможно. Found 2026-11-04 прогоном визарда на свежей
 *   копии кода, симулирующей DocumentRoot `public_html/` шаред-хостинга.
 *
 * Фикс: пока `storage/install.lock` не существует (визард не завершён),
 * провайдер не кидает — правила enforcement возвращаются сразу после
 * `markAsInstalled()`.
 */
class SafetyGateTest extends TestCase
{
    public function test_an_absent_install_lock_means_the_installer_is_still_running(): void
    {
        $this->assertFalse(
            ProductionSafetyProvider::installerFinished(sys_get_temp_dir() . '/nabilet-lock-certainly-absent-' . uniqid()),
        );
    }

    public function test_an_existing_install_lock_means_the_installer_finished(): void
    {
        $lock = tempnam(sys_get_temp_dir(), 'nabilet_lock_test_');
        $this->assertNotFalse($lock);

        file_put_contents((string) $lock, 'ok');

        try {
            $this->assertTrue(ProductionSafetyProvider::installerFinished((string) $lock));
        } finally {
            @unlink((string) $lock);
        }
    }
}
