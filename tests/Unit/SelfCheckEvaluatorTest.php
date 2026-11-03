<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\System\Support\SelfCheckEvaluator;
use Nabilet\Tests\Support\TestCase;

/**
 * Политика алертов самопроверки (`system:selfcheck`).
 *
 * Правило «орать ли на этот прогон» обязано быть чистой функцией: оно стоит
 * между «проверки собрали сигналы» и «оператор получил письмо», и ошибка в
 * нём ни для кого не красная — она молча поднимает ЛИБО глушит алерт.
 * Поэтому весь каталог переходов гоняется без фреймворка, без MySQL и без
 * файловой системы: единственный способ сделать канал алертов предсказуемым —
 * проверять его переход за переходом.
 *
 * Каталог (каждый случай — один тест):
 *   первый прогон с проблемами  -> сигнал;
 *   первый прогон чистый        -> база отсчёта, тишина;
 *   то же состояние             -> тишина (дедупликация);
 *   новая проблема              -> сигнал;
 *   проблема исчезла            -> «восстановлено»;
 *   состояние сменилось         -> сигнал (эскалация/смена состава);
 *   критичность                 -> источник уровня записи.
 */
final class SelfCheckEvaluatorTest extends TestCase
{
    /**
     * @return list<array{key: string, state: string, critical: bool, detail: string}>
     */
    private function healthy(): array
    {
        return [
            ['key' => 'database', 'state' => 'ok', 'critical' => true, 'detail' => 'd'],
            ['key' => 'redis', 'state' => 'not_configured', 'critical' => false, 'detail' => 'r'],
            ['key' => 'queue', 'state' => 'not_configured', 'critical' => false, 'detail' => 'q'],
            ['key' => 'disk', 'state' => 'ok', 'critical' => false, 'detail' => '30%'],
            ['key' => 'backup', 'state' => 'ok', 'critical' => false, 'detail' => '2.1 h'],
        ];
    }

    /**
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $base
     * @return list<array{key: string, state: string, critical: bool, detail: string}>
     */
    private function withState(array $base, string $key, string $state): array
    {
        foreach ($base as $i => $signal) {
            if ($signal['key'] === $key) {
                $base[$i]['state'] = $state;
            }
        }

        return $base;
    }

    public function test_first_run_with_problems_notify(): void
    {
        $verdict = SelfCheckEvaluator::evaluate(
            $this->withState($this->healthy(), 'queue', 'error'),
            null
        );

        $this->assertTrue($verdict['notify'], 'первый прогон с проблемами обязан поднять сигнал');
        $this->assertTrue($verdict['incident']);
        $this->assertSame(['queue'], $verdict['incident_keys']);
        $this->assertFalse($verdict['recovered'], 'первый прогон — не восстановление');
    }

    public function test_first_run_clean_is_a_baseline_not_a_recovery(): void
    {
        $verdict = SelfCheckEvaluator::evaluate($this->healthy(), null);

        $this->assertFalse($verdict['notify'], 'чистый первый прогон — база отсчёта, а не алерт');
        $this->assertNotSame('', $verdict['digest']);
    }

    public function test_unchanged_state_is_silent(): void
    {
        $signals = $this->withState($this->healthy(), 'queue', 'error');
        $first = SelfCheckEvaluator::evaluate($signals, null);
        $second = SelfCheckEvaluator::evaluate($signals, $first['digest']);

        $this->assertFalse($second['notify'], 'тот же состав проблем не повторяется каждые 10 минут');
        $this->assertSame($first['digest'], $second['digest']);
        $this->assertTrue($second['incident'], 'состояние по-прежнему инцидент');
    }

    public function test_new_problem_notify(): void
    {
        $signals = $this->withState($this->healthy(), 'queue', 'error');
        $first = SelfCheckEvaluator::evaluate($signals, null);

        // Диск переполнился поверх очереди: состав проблем изменился. Сигнал
        // собирается так же, как собирает `SelfCheckCommand`: `error` на
        // диске помечается критичным (см. `HostChecks::diskSpace()`).
        $escalated = $this->withState($signals, 'disk', 'error');
        foreach ($escalated as $i => $signal) {
            if ($signal['key'] === 'disk') {
                $escalated[$i]['critical'] = true;
            }
        }

        $second = SelfCheckEvaluator::evaluate($escalated, $first['digest']);

        $this->assertTrue($second['notify'], 'новая проблема — событие');
        $this->assertTrue($second['critical'], 'ошибка диска критична');
        $this->assertSame(['disk', 'queue'], $second['incident_keys']);
    }

    public function test_resolved_problem_reports_recovery_once(): void
    {
        $first = SelfCheckEvaluator::evaluate($this->withState($this->healthy(), 'queue', 'error'), null);

        $second = SelfCheckEvaluator::evaluate($this->healthy(), $first['digest']);

        $this->assertTrue($second['notify'], 'восстановление — событие, о нём пишут');
        $this->assertTrue($second['recovered']);
        $this->assertFalse($second['incident']);
        $this->assertFalse($second['critical']);

        // Восстановленное состояние снова тихо.
        $third = SelfCheckEvaluator::evaluate($this->healthy(), $second['digest']);
        $this->assertFalse($third['notify']);
    }

    public function test_state_change_without_key_change_still_notify(): void
    {
        $signals = $this->healthy();
        $first = SelfCheckEvaluator::evaluate($signals, null);

        // Бэкап протух: ключ тот же, состояние другое.
        $signals = $this->withState($signals, 'backup', 'warning');
        $second = SelfCheckEvaluator::evaluate($signals, $first['digest']);

        $this->assertTrue($second['notify'], 'смена уровня — тоже событие');
        $this->assertFalse($second['recovered'], 'одна проблема не исчезла — это не восстановление');
        $this->assertFalse($second['critical'], 'warning-состояние не критично');
        $this->assertSame(['backup'], $second['incident_keys']);
    }

    public function test_not_configured_is_never_an_incident(): void
    {
        $verdict = SelfCheckEvaluator::evaluate($this->healthy(), null);

        $this->assertFalse($verdict['incident']);
        $this->assertFalse(SelfCheckEvaluator::hasCritical($this->healthy()));
    }

    public function test_error_without_critical_flag_is_not_a_critical_alert(): void
    {
        // Точное зеркало семантики `HealthProbe`: очередь — некритичная
        // зависимость, её отказ — degraded, а не down. Критичность — это
        // флаг сигнала, а не функция от состояния; иначе отказ очереди
        // получал бы уровень записи как у падшей базы.
        $signals = $this->withState($this->healthy(), 'queue', 'error');

        $verdict = SelfCheckEvaluator::evaluate($signals, null);

        $this->assertTrue($verdict['incident']);
        $this->assertFalse($verdict['critical']);
    }

    public function test_critical_is_true_only_on_critical_signal_in_error(): void
    {
        $criticalSignals = [
            ['key' => 'database', 'state' => 'error', 'critical' => true, 'detail' => 'd'],
        ];
        $warningSignals = [
            ['key' => 'backup', 'state' => 'warning', 'critical' => false, 'detail' => '30 h'],
        ];

        $this->assertTrue(SelfCheckEvaluator::hasCritical($criticalSignals));
        $this->assertFalse(SelfCheckEvaluator::hasCritical($warningSignals));
        $this->assertFalse(SelfCheckEvaluator::hasCritical([]));
    }

    public function test_digest_distinguishes_compositions(): void
    {
        $clean = SelfCheckEvaluator::digest($this->healthy());
        $queued = SelfCheckEvaluator::digest($this->withState($this->healthy(), 'queue', 'error'));

        $this->assertNotSame($clean, $queued);
        $this->assertSame($clean, SelfCheckEvaluator::digest($this->healthy()), 'один и тот же состав — один отпечаток');
    }
}
