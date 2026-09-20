<?php

declare(strict_types=1);

/*
 * Web routes exist for the installer (/install) and the admin SPA shell.
 *
 * Neither is built yet — they land in phases P3 (admin) and P4 (storefront) — so
 * this file is intentionally empty. It must exist because bootstrap/app.php
 * registers it, and an empty file is honest: no half-wired routes.
 *
 * The health check is provided by Laravel at GET /up, not here.
 */
