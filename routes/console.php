<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Closure commands and scheduled tasks live here.
 *
 * Two schedulers are required by the domain and are registered by their modules
 * in later phases, not here:
 *   - hold sweeper      — releases expired seat holds (ТЗ §24, NABILET_HOLD_TTL)
 *   - session expiry    — moves tickets to `expired` after a session ends
 *
 * Until then this stays empty; an artisan schedule that references missing jobs
 * fails the whole scheduler.
 */

// Очистка просроченных броней мест — запуск каждую минуту
// Требуется для шаред-хостинга где нет постоянных воркеров
// На Timeweb настроить в Crontab: * * * * * /opt/php82/bin/php /path/to/artisan schedule:run
Schedule::command('seats:clear-expired')->everyMinute();

