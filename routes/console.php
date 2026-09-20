<?php

declare(strict_types=1);

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
