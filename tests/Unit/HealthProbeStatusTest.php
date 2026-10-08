<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\System\Services\HealthProbe;
use Nabilet\Tests\Support\TestCase;

/**
 * Правило вердикта для `GET /api/v1/health`.
 *
 * Проверяется чистая часть `HealthProbe::statusFor()` — та, где решается,
 * какой статус и код ответа получит монитор. Сами проверки (запрос в БД,
 * `Queue::size()`, Redis) требуют окружения и проверяются живьём в
 * `tests/Feature/System/HealthEndpointTest.php`.
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЕ ТЕСТЫ, А НЕ ОДИН ИНТЕГРАЦИОННЫЙ
 *   Ключевое правило — «критичный отказ перекрывает деградацию» — иначе
 *   проверялось бы только отключением MySQL, то есть не проверялось бы
 *   никогда в CI. Здесь оно проверяется комбинациями состояний, включая те,
 *   которые в живом окружении одновременно не встречаются.
 */
final class HealthProbeStatusTest extends TestCase
{
    /** @return array{state: string, critical: bool} */
    private function check(string $state, bool $critical): array
    {
        return ['state' => $state, 'critical' => $critical];
    }

    public function testAllHealthyIsOk(): void
    {
        $status = HealthProbe::statusFor([
            'database' => $this->check(HealthProbe::OK, true),
            'redis' => $this->check(HealthProbe::OK, false),
            'queue' => $this->check(HealthProbe::OK, false),
        ]);

        $this->assertSame(HealthProbe::OK, $status);
        $this->assertSame(200, HealthProbe::httpCodeFor($status));
    }

    public function testNotConfiguredDependencyDoesNotDegrade(): void
    {
        // Установка без Redis — штатная конфигурация (`CACHE_STORE=file`).
        // Она обязана быть `ok`, иначе алерт на `degraded` обесценится.
        $status = HealthProbe::statusFor([
            'database' => $this->check(HealthProbe::OK, true),
            'redis' => $this->check(HealthProbe::NOT_CONFIGURED, false),
            'queue' => $this->check(HealthProbe::NOT_CONFIGURED, false),
        ]);

        $this->assertSame(HealthProbe::OK, $status);
    }

    public function testNonCriticalFailureIsDegradedButStillTwoHundred(): void
    {
        // Требование docs/PLAN.md 6.5: деградация Redis не роняет систему.
        // 503 здесь вывел бы живой узел из ротации из-за отказа
        // необязательной зависимости.
        $status = HealthProbe::statusFor([
            'database' => $this->check(HealthProbe::OK, true),
            'redis' => $this->check(HealthProbe::ERROR, false),
            'queue' => $this->check(HealthProbe::OK, false),
        ]);

        $this->assertSame(HealthProbe::DEGRADED, $status);
        $this->assertSame(200, HealthProbe::httpCodeFor($status));
    }

    public function testQueueFailureAloneIsDegraded(): void
    {
        $status = HealthProbe::statusFor([
            'database' => $this->check(HealthProbe::OK, true),
            'redis' => $this->check(HealthProbe::OK, false),
            'queue' => $this->check(HealthProbe::ERROR, false),
        ]);

        $this->assertSame(HealthProbe::DEGRADED, $status);
    }

    public function testCriticalFailureIsDownAndFiveOhThree(): void
    {
        $status = HealthProbe::statusFor([
            'database' => $this->check(HealthProbe::ERROR, true),
            'redis' => $this->check(HealthProbe::OK, false),
            'queue' => $this->check(HealthProbe::OK, false),
        ]);

        $this->assertSame(HealthProbe::DOWN, $status);
        $this->assertSame(503, HealthProbe::httpCodeFor($status));
    }

    public function testCriticalFailureOutranksNonCriticalRegardlessOfOrder(): void
    {
        // Критичный отказ обязан перекрывать деградацию при любом порядке
        // проверок: иначе `down` мог бы замаскироваться под `degraded`, и
        // монитор не узнал бы, что база лежит.
        $degradedFirst = HealthProbe::statusFor([
            'redis' => $this->check(HealthProbe::ERROR, false),
            'database' => $this->check(HealthProbe::ERROR, true),
        ]);

        $criticalFirst = HealthProbe::statusFor([
            'database' => $this->check(HealthProbe::ERROR, true),
            'redis' => $this->check(HealthProbe::ERROR, false),
        ]);

        $this->assertSame(HealthProbe::DOWN, $degradedFirst);
        $this->assertSame(HealthProbe::DOWN, $criticalFirst);
    }

    public function testOnlyDownMapsToFiveOhThree(): void
    {
        $this->assertSame(200, HealthProbe::httpCodeFor(HealthProbe::OK));
        $this->assertSame(200, HealthProbe::httpCodeFor(HealthProbe::DEGRADED));
        $this->assertSame(503, HealthProbe::httpCodeFor(HealthProbe::DOWN));
    }
}
