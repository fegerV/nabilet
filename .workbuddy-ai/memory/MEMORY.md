# NABILET — заметки

Laravel 13 монолит (`app/Modules/*`) + Vue 3 SPA (`resources/js`), Vite→`dist/` (НЕ в git), hash-роутинг; артефакт деплоя — `public_html/`. «Почему так» — докблоки + `memory/{SEO,OPS,FRONTEND,PITFALLS}.md` + `docs/*` + дневные логи.

## Запуск
- PHP `C:/Users/Professional/AppData/Local/VertexCMS/tools/php/php.exe`; Node `.../22.22.2-6/node.exe`.
- Типы `node node_modules/vue-tsc/bin/vue-tsc.js --noEmit`; сборка `node node_modules/vite/bin/vite.js build --config vite.config.ts` (НЕ legacy `.js`).
- MySQL Docker `nabilet-mysql`:3307 — `nabilet`/`nabiletpass`; тесты `nabilet_test_suite`/`root`/`rootpass` (VM `MobyLinuxVM`, выключен → `Start-VM`). Перед тестами `SET GLOBAL log_bin_trust_function_creators=1` (иначе триггерные миграции = 1419).
- Feature `php vendor/bin/phpunit` (~6 мин); Unit `php tests/run.php [ClassName]`; `tools/lint.php` убивается песочницей → точечно `php -l`.
- `db:seed` = Admin → NotificationTemplate → SurgutVenuesSeeder (39 площадок, идемпотентно по `slug`). Стенд `admin@nabilet.local`/`admin123` (`POST /api/v1/auth/login`→`data.token`); curl к localhost `--noproxy '*'`.
- В корне файл `nul` → `rg`/`grep` по корню падает (os error 1); искать по `app/`,`tests/`,`tools/`.
- **ДУБЛЬ `artisan serve`:8000**: порт берёт победитель гонки; чужая сессия (cwd `C:\Users\Professional\nabilet\…`) → curl ходит в её БД. Диагностика `Get-CimInstance Win32_Process -Filter "Name='php.exe'"`.

## Гейты и статанализ
- 14 гейтов (все exit 0; grep ненадёжен — только коды): `tools/verify-{route-ownership,openapi,autoload,module-structure,purity,migrations,models-schema,error-envelope,state-machines,contract-schema,status-literals,spec-parity,phantom-classes}.php` + `tools/modules.php --validate`.
- **`verify-live.php` PASS** (2026-10-27: 88 таблиц/911 колонок, 0 pending). «Reported, not failed»: спека 1 триггер vs 3 живых; 29 спек-путей (`/admin/*` отдаётся ресурсными путями, `/webhooks/yookassa` → `/payments/webhooks/{provider}`, `/embed/*`, translations). Числа спеки — ТОЛЬКО `tools/spec-counts.php` (библиотека констант, не репортер).
- phpstan level 5 `--memory-limit=1G`; baseline = ratchet (только сокращать). **Larastan не ставится** (нужен `illuminate/console` ≤ ^12) → `universalObjectCratesClasses` для `Model`/`JsonResource`. `reg.exe`/`WMIC.exe` блокируются песочницей — `[OK] No errors` валиден.
- Убирая `Model::create()`, сверить `phpstan-baseline.neon`: запись станет несовпавшей → падение на `reportUnmatchedIgnoredErrors`.

## Ловушки (детали → `memory/PITFALLS.md`)
- Контроллер HTML+JSON: `Response | JsonResponse`. Фон в Bash: `&` умирает → `run_in_background: true`; НЕ `2>nul`.
- Маршруты: api — только из `routes/api.php`; web — `require` из `routes/web.php` ВЫШЕ `fallback`. `loadRoutesFrom()` = require без префикса → вторая копия. Поздняя регистрация перезаписывает (`/x/search` после `/x/{id}` = 404).
- `throw` в `DB::transaction()` откатывает побочные эффекты → ресурсы освобождать ПОСЛЕ коммита. `where('status',['a','b'])` = `= 'a'` → только `whereIn`.
- `DB::table()` НЕ знает SoftDeletes; `SoftDeletes` без `deleted_at` = `SQLSTATE[42S22]`.
- `tests/autoload.php` маппит только `Nabilet\Core|Modules|Tests`; `App\…` виден composer'у, но НЕ `tests/run.php`. В `tests/Unit/` PHP только плоско. Dependency-free база — `Nabilet\Tests\Support\TestCase` (НЕ `Nabilet\Tests\TestCase`); ассерты: `assertTrue/False/Same/NotSame/Null/NotNull/Contains/NotContains/Count/StringContainsString/Throws` — нет `assertFileExists`/`assertDoesNotMatchRegularExpression`/`assertStringEndsWith`.
- Не гонять два набора тестов параллельно: RefreshDatabase пересоздаёт БД → взаимоблокировка. Несколько сессий: перед `git add` сверять mtime.
- Tailwind 3.4 молча выбрасывает классы (`bg-gradient-*`, прозрачность не кратная 5, цвет без записи в `colors`) — проверять `grep` по `dist/assets/*.css`.
- **Схема в копиях**: канон `nabilet_core_spec/migrations.sql`, корень `migrations.sql` побайтово равен (правишь канон → `cp` → сверять `md5sum`).
- **`Validator::validated()` читает СНИМОК до `passedValidation()`**; чистая нормализация → `prepareForValidation()`, диск/сеть → в `passedValidation()`. Сторож — `EventPosterMappingTest`.
- **Гонка на уникальном индексе — только `firstOrCreate()`/`createOrFirst()`**; «вставил ли это я» — `wasRecentlyCreated`. Сторож — `UniqueRaceCopyTest`.
- **Статический сторож не должен матчить свой же комментарий**: литерал правила (`number_format(`, `$x / 100`) в пояснении роняет гейт — перефразировать.
- Меняя контракт сервиса — гнать ПОЛНЫЙ phpunit, не только модуль: Batch 46 сломал `InventoryRepositoryContractTest`.

## Инварианты: владельцы
**Полный реестр — `memory/OWNERS.md`; читать перед правкой любого модуля.** Ниже только то, что ломали чаще всего.
- Один владелец — одно правило. Нашёл копию правила (литералы словаря, скопированное тело метода, свой `number_format`) → это дефект, а не «так удобнее».
- `organization_id` — из токена, не из тела. Статус события — только `POST /events/{id}/publish|cancel`.
- `OrganizationContext`: `idOrNull()` — «может быть null, список пуст», `id()` — fail-closed. Сторож `TenantIdConversionCopyTest`.
- Словари статусов — только `Core\Support\PublicationStatus` и `StaffRole` (у `organizations.status` шире — `suspended`). Сторожа `PublicationStatusCopyTest`, `StaffRoleCopyTest`.
- Деньги — только `Money::of()/format()`; float в контракте запрещён. Сторожа `TicketPriceFormatSingleOwnerTest`, `LandingPriceFormatSingleOwnerTest`, `MoneyConversionSingleOwnerTest`.
- `public_id` — только трейт `HasPublicId`; отпечаток токена — только `Sha256Hex::of()`; подпись — `HmacSignature` + `SignedPayload`. Сторожа `PublicIdGenerationCopyTest`, `Sha256HexCopyTest`.
- Гонка на уникальном индексе — `firstOrCreate()`/`createOrFirst()` + `wasRecentlyCreated`. Сторож `UniqueRaceCopyTest`.
- Purity: `app/Core/Support` и `*/Domain/` живут без `vendor/` — прямой `config()`/`app()` там красный.
- Модель-владелец у каждой таблицы одна; новая app-only таблица = запись в `$knownExtensions` (`verify-migrations.php`) И в `APPLICATION_ONLY_TABLES` (`verify-models-schema.php`).
- Новая модель требует явный `$table` (гейт сканирует и `app/Models`).
- Деньги из внешнего источника (импорт) — `Venues\Domain\ExternalPrice` → minor units; наружу рубли не отдаём.
- `users.status` — `UserStatus` (не `PublicationStatus`). HMAC — `HmacSignature` (`deriveKey()` для ключа из HMAC).

## Витрина, зал, покупка
Детали → `memory/FRONTEND.md`: `CoordSeatMap` (клики — только `Input.dispatchMouseEvent`), раскладка `PurchaseWidget` (SVG через `meet`), импорт hallplan (`levels[].seats[]` — истина, `meta.stage_side` — данные), цены (`PATCH /inventory/sessions/{id}/prices`), кеш `GET /storefront`, ловушка «сотрудник vs организация» (`user_roles` ≠ `user_organization`), `EventPageShell`, OPcache.
