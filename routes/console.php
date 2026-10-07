<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Closure commands and scheduled tasks live here.
 *
 * Two schedulers are required by the domain and are registered by their modules
 * in later phases, not here:
 *   - hold sweeper      — releases expired seat holds (ТЗ §24; срок берётся из
 *                         `seat_holds.expires_at`, а не из NABILET_HOLD_TTL —
 *                         см. пояснение в ClearExpiredHoldsCommand)
 *   - session expiry    — moves tickets to `expired` after a session ends
 *
 * Until then this stays empty; an artisan schedule that references missing jobs
 * fails the whole scheduler.
 */

// Очистка просроченных броней мест — запуск каждую минуту
// Требуется для шаред-хостинга где нет постоянных воркеров
// На Timeweb настроить в Crontab: * * * * * /opt/php82/bin/php /path/to/artisan schedule:run
Schedule::command('seats:clear-expired')->everyMinute();

// Очередь: транзакционная почта и исходящие вебхуки кладутся в неё, чтобы
// сетевые вызовы не попадали в транзакцию оплаты. На шаред-хостинге нет
// постоянных воркеров, поэтому очередь разбирается порциями по крону:
// `--stop-when-empty` заставляет воркера выйти, как только она опустела, а
// `--max-time` гарантирует выход до следующей минуты.
//
// Lock снимается через 5 минут, а не через стандартные 24 часа: если воркер
// убьют, суточная блокировка остановила бы всю почту до ручного вмешательства.
Schedule::command('queue:work --stop-when-empty --max-time=50 --queue=default')
    ->everyMinute()
    ->withoutOverlapping(5);

// Повторы доставки вебхуков, у которых наступил next_retry_at. Ретрит не сама
// джоба, а эта команда: решение о повторе принимает RetryPolicy, который не
// повторяет 4xx — иначе мы превратились бы в источник нагрузки на чужой сервер.
Schedule::command('webhooks:retry-pending')->everyMinute();

