<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\System\Support\ProductionSafetyAudit;
use RuntimeException;

/**
 * Защита от небезопасных production-конфигураций (ТЗ §79).
 *
 * Значения по умолчанию в шаблоне окружения опасны ровно тогда, когда их
 * копируют в продакшен не задумываясь, — а именно так и деплоят по инструкции
 * «скопируйте `.env.example` в `.env`». Каждое из них означает конкретный
 * инцидент:
 *
 *  - `APP_DEBUG=true`: любое необработанное исключение возвращает страницу со
 *    стектрейсом, SQL-запросами, фрагментами исходников и переменными окружения;
 *  - `PAYMENT_DEMO_MODE=true`: `POST /payments/demo-pay` подтверждает оплату
 *    анонимным запросом — билет выдаётся без денег;
 *  - `TRUSTED_PROXIES=*`: `X-Forwarded-For` от кого угодно, поэтому лимитер
 *    считает каждый запрос новым IP (брутфорс паролей), а IP-allowlist вебхуков
 *    перестаёт что-либо значить (подделка уведомлений об оплате).
 *
 * Fail-closed выбран сознательно: приложение, которое не поднялось, дешевле
 * приложения, которое утекло. Проверка срабатывает только в `production`, поэтому
 * локальная разработка, тесты и установщик (он пишет `APP_ENV=production` уже с
 * безопасными значениями) не затронуты.
 *
 * САМИ ПРАВИЛА ЗДЕСЬ НЕ ЖИВУТ — они в `ProductionSafetyAudit`. Провайдер решает
 * только одно: когда падать. Он видит единственный момент — `boot()`, и только в
 * продакшене, поэтому на стенде с `APP_ENV=staging` он молчит, и небезопасная
 * конфигурация всплыла бы уже после переключения трафика. Тот же набор правил
 * вызывается командой `config:validate` явно и до деплоя. Две копии правил
 * разошлись бы молча — поэтому копии нет.
 */
final class ProductionSafetyProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $violations = $this->app->make(ProductionSafetyAudit::class)->violations();

        if ($violations === []) {
            return;
        }

        // ВИЗАРД ЕЩЁ НЕ ЗАВЕРШЁН. Свежий шаред-хостинг приходит сюда ДО .env:
        // Laravel подставляет дефолты (APP_ENV=production в config/app.php,
        // CACHE_STORE=database в config/cache.php), то есть каждая проверка
        // «нарушает», хотя оператор ещё не вводил ни одной настройки. Упасть
        // здесь значит сделать сам установщик (/install) недоступным —
        // исправить конфигурацию через веб было бы невозможно. Пока открыт
        // install.lock-гейт (`InstallerController::isInstalled`), нарушениям
        // разрешено подождать: тот же набор правил вызывается явной командой
        // `config:validate` и вернётся строго сразу после окончательной
        // установки (`markAsInstalled()` пишет install.lock).
        if (! self::installerFinished($this->app->storagePath('install.lock'))) {
            Log::warning('Production safety: violations found before installation; '
                . 'enforcing after the installer finishes', ['violations' => $violations]);

            return;
        }

        throw new RuntimeException(
            "Unsafe production configuration:\n  - " . implode("\n  - ", $violations)
        );
    }

    /**
     * Флаг «визард завершился» — существует `storage/install.lock`.
     *
     * Отдельный чистый метод, а не `is_file()` inline: единственное место,
     * где решается вопрос «enforce или подождать», должно быть назвать
     * тестом (см. tests/Feature/System/SafetyGateTest.php) без поднятия
     * приложения.
     */
    public static function installerFinished(string $lockPath): bool
    {
        return is_file($lockPath);
    }
}
