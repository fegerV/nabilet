<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\System\Services\HealthProbe;
use Nabilet\Modules\System\Services\HostChecks;
use Nabilet\Modules\System\Support\SelfCheckEvaluator;

/**
 * Самопроверка установки по расписанию — сигналы, которые не ждут опроса.
 *
 * ПРОБЛЕМА, КОТОРУЮ ЗАКРЫВАЕТ КОМАНДА
 *   До её появления все health-сигналы были ПАССИВНЫМИ: `GET /api/v1/health`
 *   и админская диагностика отвечают только тому, кто спросил. Внешний
 *   uptime-монитор видит код ответа — но `HealthProbe` сознательно отвечает
 *   200 и на `degraded` (деградация не выводит узел из ротации), значит
 *   отказ очереди, протухший бэкап и наполняющийся диск невидимы для любого
 *   внешнего опроса. Их видит только человек, открывший админ-страницу, —
 *   то есть никто, пока что-то ещё не сломалось.
 *
 *   Эта команда спрашивает сама себя каждые 10 минут и пишет в канал
 *   алертов при изменении состояния.
 *
 * ОДИН ВЛАДЕЛЕЦ КАЖДОГО ОТВЕТА
 *   Зависимости — `HealthProbe`, диск и бэкапы — `HostChecks`. Команда НЕ
 *   повторяет ни одного правила: она собирает сигналы, а решение «орать ли»
 *   — чистый `SelfCheckEvaluator`; вердикт «живо ли» —
 *   `HealthProbe::statusFor()` по зависимостям и только по ним: протухший
 *   бэкап и заполненный диск не делают узел мёртвым, они видны уровнем
 *   сигнала в журнале алертов.
 *
 * ДЕДУПЛИКАЦИЯ
 *   Один и тот же состав проблем не повторяется (иначе алерт каждые 10 минут
 *   превратился бы в шум, который перестают читать). Пишутся: появление
 *   инцидента, эскалация/смена состава и восстановление. Прошлое состояние —
 *   в кэше; отказ кэша НЕ подавляет алерт (fail-open в сторону лишнего
 *   сигнала, не в сторону тишины).
 *
 * ТРАНСПОРТ — НАМЕРЕННО НЕ ОЧЕРЕДЬ
 *   1. Канал `alerts` (`storage/logs/alerts-*.log`) — всегда; файл попадает в
 *      админскую страницу «Логи» автоматически (`LogReader` читает все
 *      `*.log`), и его можно хвостить/выгружать как любой лог.
 *   2. E-mail на `NABILET_ALERT_EMAIL` — если задан. Отправка синхронная и
 *      снаружи очереди: алерт «очередь не отвечает», поставленный в эту же
 *      очередь, не дошёл бы именно тогда, когда нужнее всего.
 *   Matrix-канал сотрудников сознательно не задействован: его джобы живут в
 *   той же очереди. Если оператор хочет алерты в Element X — исходя-
 *   щим транспортом должен стать синхронный, отдельное решение.
 *
 * КОД ВЫХОДА
 *   FAILURE при инциденте (в том числе повторном): ручной прогон должен
 *   видеть провал, а не «тихо ничего не сделал» — тот же контракт, что у
 *   `backup:run`.
 */
final class SelfCheckCommand extends Command
{
    /** @var string */
    protected $signature = 'system:selfcheck
        {--force : Повторить сигнал об ИНЦИДЕНТЕ, даже если состояние не изменилось}';

    /** @var string */
    protected $description = 'Самопроверка установки: БД, Redis, очередь, диск, свежесть бэкапа; сигнал при изменении состояния';

    /** Ключ кэша с отпечатком прошлого состояния. */
    private const STATE_CACHE_KEY = 'system:selfcheck:digest';

    public function handle(): int
    {
        $probe = $this->laravel->make(HealthProbe::class);
        $checks = $probe->checks();

        $signals = $this->signals($checks);
        $previous = $this->previousDigest();
        $verdict = SelfCheckEvaluator::evaluate($signals, $previous);

        // Вердикт — по зависимостям и ТОЛЬКО по ним: это контракт `statusFor`
        // (keyed-массив checks), и назначение у него прежнее — «живо ли».
        // Состояние диска и свежесть бэкапа в вердикт не вмешиваются: ни то
        // ни другое не делает узел мёртвым.
        $status = HealthProbe::statusFor($checks);

        // `--force` повторяет сигнал об ИНЦИДЕНТЕ (о нём уже писали, оператор
        // просит ещё раз); на здоровом состоянии forcing-а нет — извещать о
        // здоровье «принудительно» нечего.
        if ($verdict['notify'] || ((bool) $this->option('force') && $verdict['incident'])) {
            $this->emit($signals, $verdict);
        }

        $this->persist($verdict['digest']);

        foreach ($verdict['incident_keys'] as $key) {
            $this->warn(sprintf('[%s] %s', $status, $key));
        }

        if ($verdict['incident_keys'] === []) {
            $this->info("status: {$status}");
        }

        return $verdict['incident'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Собрать сигналы всех проверок. Один сигнал — одна проверка; состав
     * списка и есть «состояние системы», которое дедуплицируется.
     *
     * @param  array<string, array{state: string, critical: bool}>  $checks
     * @return list<array{key: string, state: string, critical: bool, detail: string}>
     */
    private function signals(array $checks): array
    {
        $host = $this->laravel->make(HostChecks::class);

        $signals = [];

        foreach ($checks as $key => $check) {
            $signals[] = [
                'key' => $key,
                'state' => $check['state'],
                'critical' => $check['critical'],
                'detail' => $check['state'] === HealthProbe::ERROR
                    ? 'Проверка не пройдена'
                    : 'Состояние: ' . $check['state'],
            ];
        }

        $disk = $host->diskSpace();
        $diskState = is_string($disk['status'] ?? null) ? $disk['status'] : 'error';
        $signals[] = [
            'key' => 'disk',
            'state' => $diskState,
            'critical' => $diskState === 'error',
            'detail' => isset($disk['used_percent'])
                ? sprintf('Занято %.2f%%, свободно %.2f ГБ', $disk['used_percent'], $disk['free_gb'])
                : (string) ($disk['message'] ?? 'Диск нечитаем'),
        ];

        $backup = $host->lastBackup();
        $backupState = is_string($backup['status'] ?? null) ? $backup['status'] : 'error';
        $signals[] = [
            'key' => 'backup',
            'state' => $backupState,
            // Нет каталога/нет копий — критично: до трафика без бэкапов нельзя.
            'critical' => $backupState === 'error',
            'detail' => isset($backup['age_hours'])
                ? sprintf('Последняя копия %s (%.1f ч назад)', $backup['last_backup'], $backup['age_hours'])
                : (string) ($backup['message'] ?? 'Копии не найдены'),
        ];

        return $signals;
    }

    /**
     * Отпечаток прошлого состояния. `null` — первый прогон (база отсчёта).
     */
    private function previousDigest(): ?string
    {
        $state = Cache::get(self::STATE_CACHE_KEY);
        $digest = is_array($state) ? ($state['digest'] ?? null) : null;

        return is_string($digest) && $digest !== '' ? $digest : null;
    }

    /**
     * Записать сигнал и, если настроено, письмо. Уровень записи берётся из
     * критичности: критический отказ — `critical`, предупреждение — `warning`,
     * восстановление — `info`.
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     * @param  array{digest: string, incident: bool, incident_keys: list<string>, critical: bool, notify: bool, recovered: bool}  $verdict
     */
    private function emit(array $signals, array $verdict): void
    {
        if ($verdict['recovered']) {
            Log::channel('alerts')->info('Самопроверка: состояние восстановлено', [
                'state' => 'recovered',
                'signals' => $this->signalMap($signals),
            ]);

            $this->sendEmail('NABILET: состояние восстановлено', $this->body($signals, $verdict));

            return;
        }

        $level = $verdict['critical'] ? 'critical' : 'warning';
        $keys = implode(', ', $verdict['incident_keys']);

        Log::channel('alerts')->{$level}('Самопроверка: обнаружены проблемы', [
            'state' => 'incident',
            'incident_keys' => $verdict['incident_keys'],
            'digest' => $verdict['digest'],
            'signals' => $this->signalMap($signals),
        ]);

        $this->sendEmail(
            sprintf('NABILET: самопроверка — проблемы (%s)', $keys),
            $this->body($signals, $verdict)
        );
    }

    /**
     * Компактная карта сигнал→состояние для контекста записи. Полный `detail`
     * можно запросить в админке; в логе он нужен только для расхождений.
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     * @return array<string, string>
     */
    private function signalMap(array $signals): array
    {
        $map = [];

        foreach ($signals as $signal) {
            $map[$signal['key']] = $signal['state'];
        }

        return $map;
    }

    /**
     * Тело письма: человекочитаемо, без персональных данных (сигналы
     * операционные).
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     * @param  array{digest: string, incident: bool, incident_keys: list<string>, critical: bool, notify: bool, recovered: bool}  $verdict
     */
    private function body(array $signals, array $verdict): string
    {
        if ($verdict['recovered']) {
            return "Самопроверка NABILET: ранее наблюдавшиеся проблемы больше не "
                . 'воспроизводятся. Состав проверок: БД, Redis, очередь, диск, '
                . "свежесть резервной копии.\n"
                . now()->toIso8601String();
        }

        $lines = ['Самопроверка NABILET: ' . implode(', ', $verdict['incident_keys']), ''];

        foreach ($signals as $signal) {
            if ($signal['state'] === 'ok' || $signal['state'] === 'not_configured') {
                continue;
            }

            $lines[] = sprintf('[%s] %s: %s', $signal['state'], $signal['key'], $signal['detail']);
        }

        $lines[] = '';
        $lines[] = 'Сигнал повторяется при изменении состояния; включается --force.';

        return implode("\n", $lines);
    }

    private function sendEmail(string $subject, string $body): void
    {
        $address = trim((string) (config('nabilet.alerts.email') ?? ''));

        if ($address === '') {
            return; // канал не настроен — это не ошибка, есть лог
        }

        try {
            Mail::raw($body, static function ($message) use ($address, $subject): void {
                $message->to($address)->subject($subject);
            });
        } catch (\Throwable $e) {
            // Письмо — дублирующий транспорт: провал не роняет команду и не
            // блокирует запись в канал. §66 E2: в журнал — код, не текст.
            Log::channel('alerts')->error('Алерт-письмо не отправлено', [
                'code' => $e::class,
            ]);
        }
    }

    private function persist(string $digest): void
    {
        try {
            Cache::forever(self::STATE_CACHE_KEY, ['digest' => $digest, 'at' => now()->toIso8601String()]);
        } catch (\Throwable) {
            // Не смогли запомнить состояние — на следующем прогоне алерт
            // повторится. Лишний сигнал лучше тишины.
        }
    }
}
