# One-click обновление NABILET Core (WordPress-подобный сценарий)

Дизайн-документ. Статус: реализовано (код, API, админка и тесты).

## 1. Коротко

Одна кнопка в админке («Проверить обновления» → «Обновить») создаёт запись
обновления и возвращает `202`. Вся тяжёлая работа — скачивание, проверка подписи,
распаковка, подмена файлов, миграции, проверка — делается **шагами конечного
автомата в `php artisan nabilet:update:tick`**, который дёргает раз в минуту
уже существующий `schedule:run`. HTTP-запрос остаётся коротким: клиент опрашивает
`/status` каждые 2–3 секунды.

Причины именно такой схемы:

- на шаред-хостинге нет постоянных воркеров, а `queue:work` в кроне выходит через
  `--max-time=50` — долгая задача обновления была бы убита;
- клиент `resources/js/lib/api.ts` имеет таймаут 20 с, а хостинг — свой лимит;
- после подмены файлов **текущий PHP-процесс остаётся со старым кодом в памяти**:
  migration/autoload/Cache должны выполняться следующим тиком, уже новым процессом.

Отличие от WordPress: подпись релиза, бэкап до обновления, staging, откат и
страница-заглушка на время обновления. WordPress обновляет файлы «поверх» и не умеет откатываться;
 здесь на кону билеты и деньги, поэтому откат — часть механизма.

## 2. Что в проекте уже есть (и что использовать)

| Возможность | Где | Как использовать |
|---|---|---|
| Версия приложения | `config/nabilet.php` → `version` (1.16.0), дублируется в `package.json`; отдаётся в `/api/v1/health` и `/admin/system/status` | Единственный источник «текущая версия» и проверки после обновления |
| Админ-роуты | `app/Modules/System/routes/api.php`: `auth:api` + `admin` + `EnsurePlatformAdmin` | Новые эндпоинты — в этом же файле или в новом, но обязательно добавить в `$moduleRoutes` в `routes/api.php` (иначе не будет префикса `/api/v1`) |
| Подтверждение опасного действия | `confirm_phrase` === `MIGRATE` в `SystemStatusController::migrate()`, `REFUND` в `AgentActionService::approve()` + поле в `AdminAgentPage.vue` | Фразы `UPDATE` / `ROLLBACK` через `strtoupper(trim(...))`, 422 `CONFIRMATION_REQUIRED` |
| Аудит | `Log::channel('security')->warning(...)` с `user_id` и `ip`, читается в админке «Логи» | Каждое действие обновления пишется сюда |
| Миграции без SSH | `POST /api/v1/admin/system/migrate` → `Artisan::call('migrate', ['--force' => true])` | Переиспользовать подход, но вызывать из тика, не из HTTP |
| Бэкап по кнопке | `RunBackupJob::dispatch($mode, $userId)`, статус в `YandexDiskSettings::recordRun()`; `GET /admin/backups/settings` | Обновление стартует только при свежем бэкапе |
| Свежесть бэкапа и диск | `HostChecks::lastBackup()` (порог 26 ч), `HostChecks::diskSpace()` | Preflight |
| SHA-256 | `hash_file('sha256', …)` в `MediaService`, `.sha256` рядом с релизом от `scripts/build-hosting-zip.ps1` | Проверка архива |
| HMAC + `hash_equals()` | `HmacSignature`, `BackupEncryptor` | Константное сравнение хэшей/подписей |
| Состояние с контрольной суммой | Киоск: `issueUpdate()` → `updateDirective()` → `updateResult()`, константы `pending/applied/failed` | Модель «директива + статус + отчёт» для журнала обновлений |
| Атомарная публикация | `HallService::publish()` — блокировка, конфликт ревизий, архивация перед публикацией | Идея «архивировать старое перед подменой» |
| Очистка кеша | `cache:clear`, `config:clear`, `view:clear` в `SystemStatusController`, `route:clear` в `InstallerController` | Вызывать после подмены, в новом процессе |
| Сигнал деградации | `SelfCheckCommand` + канал `alerts` | «Обновление зависло», «автооткат», «БД требует внимания» |
| Тесты | `tests/Feature/System/*`, `tests/Feature/Backups/*` | `RefreshDatabase`, `actingAs($admin,'api')`, фабрика админа с `user_roles` |

Важно: провайдеры модулей **не** бутятся автоматически (`config('nabilet.modules')` —
просто реестр). Команды нужно явно зарегистрировать в `bootstrap/app.php::withCommands()`,
как это сделано для `backup:run` и `system:selfcheck`.

## 3. Сервер обновлений

`scripts/build-hosting-zip.ps1` уже собирает самодостаточный архив с `vendor/` и
`dist/`, проверяет записи и пишет `.sha256`. Добавляем генерацию `manifest.json`:

```json
{
  "schema": 1,
  "version": "1.17.0",
  "released_at": "2026-11-25T12:00:00Z",
  "channel": "stable",
  "artifact": "nabilet-hosting-v1.17.0-20261125-120000.zip",
  "sha256": "<64 hex>",
  "size_bytes": 17406336,
  "php": {
    "min": "8.3.0",
    "max_exclusive": "9.0.0",
    "extensions": ["pdo", "mbstring", "openssl", "json", "curl", "zip"]
  },
  "from_versions": ["1.15.0", "1.16.0"],
  "migrations": 2,
  "signature": "<base64 подписи канонических байт манифеста>"
}
```

- Канонические байты = `json_encode` полей в фиксированном порядке, без `signature`.
- Подпись RSA/ECDSA через `openssl_verify($canonical, $sig, $publicKey, OPENSSL_ALGO_SHA256)`;
  `ext-openssl` уже обязателен в `composer.json`. `ext-sodium` в зависимостях нет — не использовать.
- Приватный ключ живёт в CI/секрете сборки; на сайте зашит только публичный ключ
  (`config/updates.php`). Компрометация сервера раздачи не даёт подсунуть архив.
- Источники манифеста: HTTPS-лента обновлений, ручная загрузка zip + `.sha256` через
  админку (для установок без интернета), локальный `releases/` (self-hosted). Все три
  проходят через один `ManifestVerifier`.
- Скачивание: только HTTPS, allowlist хоста, без редиректов на другой хост, таймаут
  15 с, лимит 64 МБ. Текущий архив ~16,6 МБ, закладываем минимум 3× на диске.

## 4. Состояния обновления

Хранится в таблице `system_updates` + дублируется в `storage/framework/updates/<id>.json`
(обычный файл в неперезаписываемом `storage/` — статус выживает при смене кода).

| Статус | Исполнитель | Шаг | Переход |
|---|---|---|---|
| `checking` | HTTP `check` (кэш 15 мин) | лента, подпись, версия, changelog | `available` / `error` |
| `preparing` | tick | бэкап → скачивание → sha256 → распаковка в staging → preflight | `ready` / `failed` |
| `ready` | tick | ждёт подтверждения/окна; запись `locked_at` | `applying` |
| `applying` | tick N | maintenance ON → переименование каталогов → статус `migrating`, **выход** | `migrating` |
| `migrating` | tick N+1, новый код | `migrate --force`, `cache:clear`, `config:clear`, `route:clear`, `view:clear`, статус `verifying`, **выход** | `verifying` / `failed_after_swap` |
| `verifying` | tick N+2 | снять maintenance → HTTP `/api/v1/health`, версия == целевая, smoke `/`, `/admin`, `/api/v1/ping` | `done` / `rolling_back` |
| `done` | — | старый набор каталогов хранится 2 релиза | кнопка «Откатить» |
| `failed` | — | ошибка **до** подмены: сайт не тронут | повтор / отмена |
| `failed_after_swap` | — | ошибка миграции/проверки: **maintenance остаётся включённым**, алерт в `alerts`, ручной выбор | «Откатить код» / «Доработать вручную» |
| `rolling_back` → `rolled_back` | tick | вернуть каталоги из backup-а, снять maintenance, очистить кеш | — |

Разделение по тикам принципиально: в процессе N загружен старый код, в N+1 — уже
новый. Попытка сделать `migrate` и `Artisan::call` в том же процессе после подмены
работала бы со старым autoload-ом и старыми классами миграций.

Блокировки: `withoutOverlapping(5)` на `nabilet:update:tick` в `routes/console.php`
плюс `locked_at` в `system_updates` (одновременно не более одного обновления).
Повтор тика идемпотентен: он делает ровно один переход и сверяет ожидаемый статус.

## 5. Подмена файлов

Релизу принадлежат: `app/`, `bootstrap/` (кроме `cache/`), `config/`, `database/`, `lang/`,
`resources/`, `routes/`, `dist/`, `vendor/`, `public/`, `artisan`, `composer.json`,
`composer.lock`, `.env.example`, `CHANGELOG.md`.

Никогда не трогаем: `.env`, `storage/**`, `bootstrap/cache/**`,
`public_html/.htaccess` (может быть переписан панелью TimeWeb), `.user.ini`,
`favicon.svg`, `robots.txt`, `public_html/images/**` (загруженные афиши),
`public_html/storage` (симлинк). Из `public_html/` обновляем только `index.php` и
`assets/` по белому списку.

Процедура на одном томе (`rename()` — атомарная операция):

1. в `storage/framework/updates/<id>/backup/` архивируем текущие каталоги по одному;
2. из `.updates/staging/<id>/` кладём новые на их место;
3. возвращаем `.env`, `storage`, `bootstrap/cache`, пользовательские файлы `public_html/`;
4. при любом сбое — `UpdateRollback` возвращает каталоги из backup-а.

Maintenance включается записью `storage/framework/down` (файл в `storage`, который
не перезаписывается) до шага 1; страница-заглушка лежит в `public_html/maintenance.html`
и тоже не входит в архив. Снимается тиком `verifying` после успешных миграций.

Альтернатива для хостов с `symlink()` — `current → releases/<version>` с тонким
`public_html/index.php`, требующим `../current/public/index.php`. Даёт почти
мгновенное переключение, но меняет всю раскладку; включается флагом, не по умолчанию.

**Аварийный контур.** Если новый код не стартует, cron не сможет выполнить
`artisan`. Рядом с cron-строкой указываем запасной вызов скрипта из `storage/`,
которой обновление не трогает:

```cron
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1 || php storage/framework/updater/recover.php <id>
```

`recover.php` не зависит от `vendor/` и `bootstrap/app.php`: только `rename()` +
удаление `down`-файла. Это единственный надёжный способ не остаться с «битым»
сайтом без SSH.

## 6. Миграции и откат

- expand/contract: миграция версии N не удаляет то, что ещё читает код N-1;
  удаление — только в N+1. Иначе откат кода безопасен только на бумаге.
- MySQL не делает DDL полностью транзакционным. После падения миграции БД может
  быть частично изменена, поэтому **автоматического `migrate:rollback` нет** —
  он может уничтожить данные. Откатывается только код; БД остаётся, админ получает
  явное предупреждение и инструкцию.
- Preflight проверяет `Migrator::getPendingMigrations()` — если после прошлой
  попытки остались неприменённые миграции, обновление не стартует.
- Downgrade блокируется списком `from_versions` в манифесте; принудительный откат —
  отдельная кнопка с фразой `ROLLBACK` и записью в `security.log`.
- Бэкап: с подключённым Диском — `RunBackupJob::dispatch('full', $userId)`. Без
  Диска — `RunLocalBackupJob`, который вызывает новый
  `YandexDiskBackupService::createFullBackupLocal()` и оставляет `full_*.zip`
  в `storage/app/backups`. Результат пишется в
  `storage/app/updates/local-backup.json`; обновление ждёт готовности файла.
  Локальная копия слабее 3-2-1 и помечается как таковая в админке. Запретить её
  можно `NABILET_UPDATE_ALLOW_LOCAL_BACKUP=false` (тогда без Диска обновление
  блокируется кодом `BACKUP_REQUIRED`).

## 7. API

Все пути под `auth:api` + `admin` + `EnsurePlatformAdmin`, префикс `/api/v1`,
ответы в envelope §66 `{"error":{"code","message","details","request_id"}}`.

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/admin/system/update/check` | доступная версия, changelog, результат preflight (без записи), кэш 15 мин |
| POST | `/admin/system/update/start` | `{version, confirm_phrase: "UPDATE", source: "feed"|"upload"}` → `202 {update_id}`; только создаёт запись и ставит бэкап в очередь |
| POST | `/admin/system/update/upload` | multipart zip + `.sha256` для офлайн-установок |
| GET | `/admin/system/update/status` | `{update_id, status, step, version, target_version, error, started_at, finished_at}` |
| POST | `/admin/system/update/cancel` | разрешено только в `preparing`/`ready` |
| POST | `/admin/system/update/rollback` | `{confirm_phrase: "ROLLBACK"}`, из `done`/`failed_after_swap` |
| GET | `/admin/system/update/history` | последние N записей журнала |

Фраза подтверждения — защита от случайного нажатия, не от злоумышленника;
настоящие барьеры — авторизация, роль `admin` + platform-admin и подпись манифеста.
Каждое действие пишется в `Log::channel('security')` с `user_id`, `ip`, версией и
хэшем артефакта.

## 8. Preflight (блокирующие проверки)

1. `storage/install.lock` существует; `config('nabilet.version')` входит в `from_versions`.
2. PHP между `min` и `max_exclusive`, все расширения из манифеста.
3. `HostChecks::lastBackup()` моложе 26 ч и это `full`; иначе ставим `RunBackupJob`.
4. `HostChecks::diskSpace()` — свободно ≥ 3× размера архива.
5. `storage/`, `bootstrap/cache/`, каталог сайта доступны на запись.
6. Cron heartbeat свежий (метка от `nabilet:update:tick` за последние 3 минуты).
7. Нет `pending` миграций и нет другой активной записи в `system_updates`.
8. PHP 8.3+ и MySQL 8.0+ для CHECK-инвариантов (на 5.7 — предупреждение как в мастере).

## 9. Распаковка и безопасность

- Запрет записей с `..`, абсолютных путей, симлинков и путей вне whitelist,
  `.env`, `storage/`, `bootstrap/cache/`. Проверка `realpath()` каждого целевого
  файла перед записью.
- Лимит размера архива 64 МБ и лимит суммарного распакованного размера (защита от zip bomb).
- `hash_equals()` для сравнения sha256 и подписи; несовпадение → отказ до распаковки.
- `check` кэшируется на 15 мин, чтобы не ходить в сеть на каждый запрос админки.
- В `SelfCheckCommand` добавляются сигналы: обновление в `preparing` > 15 мин,
  `failed_after_swap`, несколько откатов подряд.

## 10. Vue-админка

- `AdminShell.vue::loadVersion()` уже читает `version` из `/admin/system/status` —
  туда вешается бейдж «Доступна 1.17.0».
- Новый экран `AdminUpdatesPage.vue` (роут `/admin/updates`, уже покрывается
  wildcard `/admin/{any}` в `routes/web.php`): версии, changelog, кнопки
  «Проверить», «Обновить», «Откатить», прогресс по шагам и словесный статус.
- `202` + polling каждые 2–3 с (как у бэкапов, но чаще); таймаут `api.ts` 20 с не мешает.
- Подтверждение — поле `confirm_phrase`, как в `AdminAgentPage.vue` (`REFUND`).
- `vue-i18n` в проекте нет — строки на русском, как остальные экраны.
- Раздел «Обновление на хостинге без терминала» в `AdminHelpPage.vue` заменяется
  ссылкой на новый экран; старый путь с `MIGRATE` остаётся fallback.

## 11. Тесты

1. `ManifestVerifierTest`: подмена подписи, подмена sha256, даунгрейд, неизвестный канал,
   несовместимый PHP, битый JSON.
2. `ReleaseExtractorTest`: `../`, абсолютный путь, симлинк, `.env` в архиве, zip bomb —
   целевой каталог остаётся пустым.
3. `UpdateStateMachineTest`: все переходы, повтор тика, гонка двух тиков, тупик, таймаут.
4. `UpdateApplyTest`: фикстура с `.env`/`storage`/`public_html/images`; проверка побайтовой
   сохранности и возврата при откате.
5. `UpdateMigrationFailureTest`: падающая миграция → maintenance включён, код НЕ откатан
   автоматически, `failed_after_swap`, алерт в `alerts`.
6. `UpdateApiTest`: 401/403 без `auth:api`/admin/platform-admin, 422 без фразы, 409 при
   параллельном обновлении, форма envelope §66.
7. E2E на smoke-стенде из `docs/deploy-timeweb.md`: 1.16.0 → тестовый 1.16.1 через
   админку, `/api/v1/health` отдаёт новую версию, `/` и `/admin` открываются.
8. Прогнать `php tests/run.php --strict` и `php vendor/bin/phpunit`: обычный
   `php tests/run.php` гоняет только `tests/Unit`.

## 12. План реализации

1. `config/updates.php` (лента, публичный ключ, каналы, таймауты, лимиты, число
   хранимых релизов) + блок в `.env.example`; `database/migrations/…_create_system_updates.php`.
2. `app/Modules/Updater/{Domain,Services,Console,Http}` — верификатор, загрузчик,
   распаковщик, preflight, applier, rollback, status; модель `SystemUpdate`.
3. Явная регистрация: маршрутный файл — в `$moduleRoutes` в `routes/api.php`;
   `nabilet:update:tick` — в `bootstrap/app.php::withCommands()`;
   `Schedule::command('nabilet:update:tick')->everyMinute()->withoutOverlapping(5)`
   в `routes/console.php`. Провайдер — только если понадобятся синглтоны.
4. `scripts/build-hosting-zip.ps1`: `manifest.json` + подпись в CI, публикация
   `.sha256` и манифеста.
5. Vue: `AdminUpdatesPage.vue`, бейдж в `AdminShell.vue`, правка `AdminHelpPage.vue`;
   `storage/framework/updater/recover.php` (аварийный откат).
6. Документация: `docs/deploy-timeweb.md` §9, `CHANGELOG.md`, этот файл.

## 13. Вопросы, которые нужно решить до реализации

1. Есть ли домен ленты обновлений и кто хранит приватный ключ? Без ответа остаётся
   только ручная загрузка zip в админку.
2. Автоматические фоновые обновления (как у WordPress) или только кнопка?
   Рекомендую сначала только кнопку: автоапдейт кассовой системы без наблюдателя — риск.
3. Допустима ли раскладка `current → releases/<v>` или строго сохраняем текущую
   подмену каталогов?
4. Откат БД не делать (рекомендуется) — подтвердить.
5. Кто и как часто делает `full` бэкап на Диск: обновление блокируется без него,
   и это может оказаться слишком строго для мелких клиентов.
