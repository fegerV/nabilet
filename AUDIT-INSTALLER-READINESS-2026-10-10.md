# Аудит готовности Nabilet к установке на шаред-хостинге (установочный визард)

**Дата:** 2026-10-10 · **Роль:** DevOps-ревью · **Объект:** `/workspace` (Nabilet Core, Laravel-модульный монолит)
**Фокус:** мастер установки `GET/POST /install` (`app/Modules/Installer/`), сценарий чистого шаред-хостинга (TimeWeb и аналоги).
**Ограничения:** код не изменялся; установка в production не выполнялась; данные не удалялись.

---

## 0. Объект аудита (подтверждено файловой структурой)

| Компонент | Путь | Статус |
|---|---|---|
| Контроллер визарда (493 стр.) | `app/Modules/Installer/Http/Controllers/InstallerController.php` | существует |
| Blade-мастер, 4 шага (765 стр.) | `app/Modules/Installer/resources/views/install.blade.php` | существует |
| Роуты (web-группа, чистый URL /install) | `app/Modules/Installer/routes/web.php`, подключается из `routes/web.php:103` | существует |
| Провайдер (монтирует namespace `installer::`) | `bootstrap/app.php:33-37` | зарегистрирован |
| CSRF исключён для `/install` | `bootstrap/app.php:99` (`validateCsrfTokens(except: ['install','install/*'])`) | подтверждено |
| Блокировка повторного запуска | `storage/install.lock` (`isInstalled()` / `markAsInstalled()`) | файла нет (чистое состояние) |
| Сидеры | `database/seeders/{DatabaseSeeder,NabiletAdminSeeder,NotificationTemplateSeeder}.php` | существуют |
| Миграции | `database/migrations/` — 32 файла, вкл. `2026_10_07_000500_create_queue_tables.php` | существуют |
| Health-check | `app/Modules/System/Services/HealthProbe.php` (database/redis/queue), публичный `GET /health` | существует |
| Инструкции | `README.md:81-91`, `docs/deploy-timeweb.md` (весь цикл деплоя) | существуют |
| CI | `.github/workflows/ci.yml` (jobs: verify, docker, schema, phpunit, frontend) | существует |

Мастер — 4 шага: **1 Требования → 2 База данных (+YooKassa) → 3 Администратор → 4 Выполнение**. Переходы проверены по коду JS (`goToStep`, `checkRequirements`, `submitInstallation`, `runInstall`).

---

## 1. Установка с нуля без ручного редактирования кода

**Подтверждено кодом:** по замыслу — да: визард сам пишет `.env` (`writeEnvFile`), генерирует APP_KEY, выполняет `migrate --force`, создаёт админа, `storage:link`, `db:seed`, ставит `install.lock`. До визарда штатно требуется только загрузка кода и `composer install --no-dev -o` (`vendor/` не коммитится).

**Но фактически «без ручных правок» критерий НЕ выполнен:** после финала мастера необходимо вручную править `.env` (очереди/кеш — §8, почта — §9) и удалять демо-аккаунты (§6). Плюс BLOCKER по версии PHP (§2).

## 2. Проверка версии PHP и расширений

**Подтверждено кодом:**
- Визард требует **PHP ≥ 8.1** (`checkRequirements()`, `'8.1'` hard-coded); расширения: `pdo, mbstring, openssl, json, xml, curl, zip`.
- `composer.json`: строго `"php": "^8.3"`; `docs/deploy-timeweb.md`: хостингу предписан PHP **8.3**; CI: verify на 8.3, phpunit на 8.4.

🔴 **BLOCKER-1:** на хостинге с PHP 8.1/8.2 визард пропустит шаг 1, но `Artisan::call('migrate')` или следующий же запрос упадёт на Composer platform-check → полу-установка. `$phpRequired` обязан быть `8.3`.

**Фактический запуск (песочница):** `php artisan --version` → «Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.2.34». Замечание: platform-check в `vendor/` объявляет даже ≥ 8.4.1 — шире `^8.3` из composer.json; второй источник рассогласования (проверить пересборкой lock).

## 3. Права каталогов и доступность сервисов

**Подтверждено кодом:** проверяется запись в `storage/`, `storage/logs`, `storage/framework/cache`, `storage/framework/views`, `bootstrap/cache`; наличие `.env` — справочно (не блокирует). `storage:link` в try/catch — fail-soft (на shared hosting symlink часто запрещён; warning в лог, установка продолжается — риск молча нерабочей загрузки файлов).

⚠️ **Не проверяется:** запись в корень проекта (куда пишется `.env` — ошибка всплывёт только при `File::put`), `proc_open/putenv` (нужны Artisan на части PHP-FPM-ферм), `max_execution_time`/`memory_limit` (32 миграции могут превысить веб-таймаут 30 c), доступность MySQL-хоста из сети хостинга (это делает PDO-test — ок).

## 4. Подключение к БД и проверка соединения

**Подтверждено кодом:** серверный `testDatabaseConnection()` реально открывает PDO (`mysql:host=...;dbname=...`, ERRMODE_EXCEPTION) до записи `.env`; при неудаче — установка прерывается с подсказкой про хост БД TimeWeb. ✅

🟡 **DEFECT (UX):** кнопка «Проверить подключение» на шаге 2 — **клиентская имитация**: `testDatabase()` в blade просто `setTimeout(…, 700)` и рисует «✓ Подключение проверено», без обращения к серверу. Пользователь видит «успех» даже при заведомо неверных креденшелалах; реальная проверка выполняется лишь внутри POST /install (шаг 4).

## 5. Схема БД и миграции

**Подтверждено кодом:** `Artisan::call('migrate', ['--force'=>true])`, код возврата проверен (≠0 → RuntimeException → установка не помечена успешной). Миграция триггера `2026_09_20_001100_add_schema_version_immutability_trigger.php` спроектирована fail-soft под shared MySQL (ERROR 1419 / binlog logs_updates_to_triggers не валит `migrate`) — прямая адаптация к шаред-хостингу. ✅

**Косвенные фактические артефакты (исторические логи в репозитории):** `qa-scripts/step2_migrate_run1.log` — падение на `007_analytics FAIL`; `step2_migrate_run2.log` — полный проход до последней миграции DONE; `step4_serve.log` — dev-сервер отвечал на `/` и `/api/v1/events`. Текущий end-to-end прогон не выполнялся (см. «Разделение доказательств»).

## 6. Создание первого администратора

**Подтверждено кодом:** `createAdminUser()` — через модель `User::create` (bcrypt, `email_verified_at`, `status=active`) с DB-fallback; `bootstrapOrganization()` — организация + роль `admin` + членство в **обеих** таблицах (`user_organization` и `user_roles`), что защищает от `TenantContextMissingError`; fail-soft.

🔴 **BLOCKER-2 (Security):** шаг 7 визарда вызывает `db:seed --force` → `DatabaseSeeder` → `NabiletAdminSeeder`, который идемпотентно сидирует в боевую базу трёх демо-пользователей с **известными паролями**: `admin@nabilet.local / admin123` (полная роль admin), `manager@nabilet.local / manager123`, `support@nabilet.local / support123` (`NabiletAdminSeeder.php:128-131`). Учётки создаются на КАЖДОЙ установке; пароль опубликован в исходниках.

## 7. Генерация секретов и настройка окружения

**Подтверждено кодом:** `APP_KEY = 'base64:'.random_bytes(32)` в `writeEnvFile()`; ключ заранее подменяется in-memory в config перед Artisan-командами + `DB::purge` (грамотно: `config:clear` не перечитывает Dotenv в живом процессе — комментарий в коде это объясняет). `APP_ENV=production`, `APP_DEBUG=false`, locale `ru`, TZ `Europe/Moscow`, LOG stack/debug. Валидация пароля админа min:8 + индикатор силы в UI.

🟡 **DEFECT (повторный запуск):** единственный маркер установки — `storage/install.lock`. При его потере (переезд, очистка storage) повторный визард **перезапишет `.env` новым APP_KEY** → сломаются Sanctum-токены, cookies/сессии, зашифрованные поля; подтверждения нет.
⚠️ Креды БД/YooKassa пишутся в `.env` без экранирования — пароль со `"` или переводом строки испортит файл.

## 8. Очереди, планировщик, кеш, файловое хранилище

🔴 **BLOCKER-3:** ENV_TEMPLATE жёстко пишет `QUEUE_CONNECTION=sync` и `CACHE_DRIVER=file`, тогда как:
- `config/queue.php` default — `database`; письма и вебхуки диспатчатся в очередь (`SendOrderNotificationJob`, `SendWebhookDeliveryJob`, `OrderObserver`); `docs/deploy-timeweb.md` §6 прямо требует `QUEUE_CONNECTION=database`. При `sync` отправка писем/вебхуков выполняется внутри HTTP-заказа покупателя (таймауты, деградация).
- `config/cache.php` читает **`CACHE_STORE`**, а визард пишет устаревший `CACHE_DRIVER` → драйвер молча берётся дефолт `database` (таблица cache есть — работает, но конфигурация вводит в заблуждение).
- `HealthProbe` проверяет `queue` → сразу после установки health будет `degraded`.

**Планировщик:** `routes/console.php:24` — `seats:clear-expired` everyMinute; на шаред-хостинге нужен cron `* * * * * …/artisan schedule:run`; комментарий в коде ссылается на `/opt/php82/bin/php` — **рассогласование с PHP 8.3**. Визард cron не показывает и не проверяет.
**Файлы:** `FILESYSTEM_DISK=public` + attempt `storage:link` (fail-soft, §3). Worker (`queue:work`) в мастер не включён.

## 9. Почта и платёжный провайдер

🔴 **BLOCKER-4:** мастер **не спрашивает SMTP**, и в ENV_TEMPLATE **нет ни одного `MAIL_*`** ключа → после установки доставка писем (билеты, сброс пароля) не работает; восстановление требует ручного редактирования `.env` (нарушает критерий №1).
✅ **YooKassa:** поля `yookassa_shop_id/api_key` на шаге 2 (nullable) пишутся в `YOOKASSA_SHOP_ID/YOOKASSA_SECRET_KEY`. Реальная оплата — непроверяемо без тестовых ключей провайдера.

## 10. HTTPS, домен, фоновые процессы, веб-сервер

**Подтверждено docs/артефактами:** схема шаред-деплоя в `docs/deploy-timeweb.md`: код вне webroot, `public_html/index.php` (тонкий вход на канонический `public/index.php`), `public_html/.htaccess` (rewrite + `Require all denied` для dot-files), `.user.ini` (display_errors off, expose_php off, secure session cookies, лимиты загрузки). Визард собирает `APP_URL` (валидация `url`), но **не проверяет https**-схему против реального домена и не верифицирует TLS.
Фоновые процессы: `schedule:run` и `queue:work` — вне мастера (cron/Supervisor на стороне хостинга).
**Не проверимо без сервера:** Apache/LiteSpeed rewrite, применение `.user.ini`, сертификат Let's Encrypt панели.

## 11. Безопасность установщика после завершения установки

✅ Код: `show()` и `install()` проверяют `isInstalled()` → 403 JSON `ALREADY_INSTALLED` / redirect; `markAsInstalled()` пишется только после полного успеха; внутренние сообщения исключений больше не отдаются клиенту (ранняя утечка путей/драйвера устранена).

🟡 Остаточные риски:
- До появления lock: `POST /install` **без CSRF и без throttle** — окно «race-to-install» между выкладкой кода и первым легитимным запуском (бот может установить систему на свои креды). Рекомендовано: токен мастера + rate limit.
- Надёжность блокировки зависит только от наличия файла в `storage/` (см. §7/§12).

## 12. Повторный запуск установки

✅ С `install.lock`: GET/POST `/install` заблокированы (403/redirect).
🔴 Без lock: повторный запуск деструктивен — новый APP_KEY поверх живой базы, конфликт unique email админа (→ 500 INSTALL_FAILED на середине процесса), сидеры идемпотентны (не помогут, но и не навредят повторно). Резервного режима «repair/recover» нет.

## 13. Обновление существующей установки без потери данных

Визард для апгрейда не предназначен (корректно). Штатный CLI-путь описан в `docs/deploy-timeweb.md`: `composer install --no-dev -o` → `php artisan migrate --force` → `config/route/view:cache`. Миграции аддитивны.
**Не найдено:** единого deploy-скрипта/атомического релиза (release-symlink, downtime-флаг); порядок кэшей не формализован; lossless-тест апгрейда на копии боевой базы не проводился.

## 14. Резервное копирование и восстановление

❌ **GAP:** ни в README, ни в deploy-доке, ни в `scripts/`/`qa-scripts/` нет инструкции или утилиты бэкапа (дамп БД + `.env` + `storage/app/public`) и процедуры восстановления. Для шаред-хостинга без root-доступа это критично (единственный страховочный механизм — панельные бэкапы хостинга, в docs не упомянуты).

## 15. Частично завершённая установка

При падении на любом шаге (PDO fail, migrate fail, создание админа fail): `.env` уже записан (кроме самого раннего отказа), lock отсутствует, состояние БД — на середине миграций. Повторный визард формально возможен, но перегенерирует APP_KEY и упадёт на дубликате email. **Resume/rollback/state-detection в мастере отсутствуют**; диагностика — только `storage/logs/laravel.log` (`Log::error` с трейсом) и generic-сообщение в UI.

## 16. Диагностика ошибок и health-check

✅ `GET /health` (контракт openapi.yaml) + `HealthProbe`: проверки database/redis/queue, статусы ok/degraded/down, redis корректно помечается NOT_CONFIGURED (predis опционален). JSON-конверт ошибок визарда: `VALIDATION_ERROR` (с полями), `INSTALL_FAILED`, `ALREADY_INSTALLED`. UI шага 4 показывает текст ошибки и возвращает кнопку повтора.
🟡 Шаг 4 всегда рисует все 4 галочки «успех» после 200 OK, даже если seed/symlink тихо упали в warning (нет сверки с логом/health).

## 17. Сборка и CI на чистом окружении

**Подтверждено кодом:** `.github/workflows/ci.yml`: jobs `verify` (PHP 8.3), `docker`, `schema`, `phpunit` (PHP 8.4), `frontend`; фронт собирается `npm ci && npm run build` (vite.config.ts → dist SPA). Логи `qa-scripts/step4_npm_build.log` — исторически успешная сборка.
**Не проверено здесь:** зелёный статус прогонов GitHub Actions (нет сети/секретов), сборка docker-образа, полный `composer install` (песочница на PHP 8.2 против требуемых ≥8.3).

---

## Пошаговый эталонный сценарий установки (шаред-хостинг)

1. Панель хостинга: **PHP 8.3**, создать БД MySQL + пользователя, привязать домен, включить HTTPS.
2. Загрузить код вне webroot; проверить наличие `public_html/index.php` и `.htaccess`.
3. SSH: `composer install --no-dev --optimize-autoloader` (при platform-check — менять версию PHP, не игнорировать требования).
4. Права: `storage/**`, `bootstrap/cache`, корень проекта — writable для PHP-пользователя.
5. Открыть `https://домен/install` → **Шаг 1 «Требования»**: зелёный список (после исправления BLOCKER-1); при замечаниях — чинить сервер.
6. **Шаг 2 «База данных»**: app_name, APP_URL (https), хост/порт/БД/логин/пароль (из панели хостинга), опционально YooKassa shopID/API-key.
7. **Шаг 3 «Администратор»**: email, пароль ≥8 (индикатор силы), подтверждение.
8. **Шаг 4 «Выполнение»**: POST /install = PDO-test → запись `.env` (APP_KEY, DB, YooKassa) → config/cache:clear → migrate → админ+организация+роль → storage:link (fail-soft) → db:seed ⚠️ (сейчас сеет демо-учётки — BLOCKER-2) → `install.lock`.
9. Немедленно после успеха (пока дефекты не исправлены): удалить демо-пользователей `*@nabilet.local`; вручную дописать в `.env` `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, блок `MAIL_*`.
10. Cron: `* * * * * /opt/php83/bin/php /home/uXXXXX/artisan schedule:run`; worker: Supervisor или cron-цикл `queue:work --stop-when-empty`.
11. Верификация: `GET /health` = ok; логин админом в `/admin`; тестовый заказ → письмо + вебхук; убедиться в наличии `storage/install.lock` и 403 на `/install`.
12. Первый бэкап: дамп БД + `.env` + `storage/app/public` (инструкция отсутствует — GAP §14).

## Список блокирующих проблем

| # | Severity | Проблема | Место |
|---|---|---|---|
| B-1 | 🔴 Blocker | Визард принимает PHP ≥ 8.1, зависимости требуют ^8.3 (platform-check вплоть до 8.4.1) → полу-установка с падением | InstallerController:236 vs composer.json |
| B-2 | 🔴 Blocker (Security) | `db:seed` при установке создаёт `admin@nabilet.local/admin123` с полной ролью admin (+2 демо-учётки) | NabiletAdminSeeder:128-131 |
| B-3 | 🔴 Blocker | ENV_TEMPLATE: `QUEUE_CONNECTION=sync` и несуществующий `CACHE_DRIVER` вместо `CACHE_STORE` → синхронные письма/вебхуки в HTTP, degraded health | InstallerController:52-56 |
| B-4 | 🔴 Blocker | Мастер не настраивает почту: ноль `MAIL_*` в генерируемом `.env` → уведомления не работают сразу после установки | ENV_TEMPLATE |
| W-1 | 🟡 Major | Потеря `install.lock` ⇒ повторная установка перезаписывает APP_KEY (ломает токены/сессии), без подтверждения/resume | §7, §12, §15 |
| W-2 | 🟡 Major | `POST /install` без CSRF и throttle в период до lock — race-to-install | bootstrap/app.php:99 |
| W-3 | 🟡 Major | Клиентская имитация «Проверка подключения к БД» на шаге 2 (setTimeout 700 мс) | install.blade.php `testDatabase()` |
| W-4 | 🟡 Major | Нет проверки записи в корень (`.env`), proc_open, лимитов времени/памяти; cron-комментарий ссылается на php82 | checkRequirements(); routes/console.php:23 |
| G-1 | ⚪ Gap | Нет backup/restore инструкции и deploy-скрипта обновления | README/docs/scripts |
| G-2 | ⚪ Gap | Шаг 4 показывает полный «успех» даже при warning-падениях seed/storage:link | blade `markDone()` |

## Критерии успешного развёртывания (DoD)

1. `composer install` + визард проходят на PHP 8.3 без ручных правок кода и без `--ignore-platform-reqs`.
2. После финала мастера: `GET /health` → `ok`; в БД ровно один админ (демо-учётки не создаются); вход в `/admin` работает; организация/роли привязаны.
3. Сгенерированный `.env` содержит `QUEUE_CONNECTION=database`, `CACHE_STORE`, полный блок `MAIL_*`; тестовое письмо доставлено; `jobs` пустеет воркером; webhook асинхронный.
4. `storage/install.lock` присутствует; `GET/POST /install` → 403; пре-lock период защищён (CSRF/throttle); повторный заход не меняет APP_KEY.
5. Cron `schedule:run` активен (`seats:clear-expired` выполняется); HTTPS и deny dot-files проверены на живом домене.
6. Тестовая оплата YooKassa даёт заказ + билет + письмо.
7. Документирован и однократно проверен цикл backup → restore; upgrade `migrate --force` на копии базы без потери данных.
8. CI зелёный на чистом раннере (verify/phpunit/frontend/schema).

## Разделение доказательств

- **Подтверждено кодом (чтение исходников):** всё в §1–§12, §16, §17 (InstallerController, blade-мастер, роуты, bootstrap/app.php, config/queue|cache, сидеры, миграции, docs, CI).
- **Проверено фактическим запуском:** воспроизведённое падение Composer platform-check на локальном PHP 8.2; исторические артефакты qa-scripts (migrate run1 FAIL→run2 DONE, dev-serve отвечает, npm build ок). Полный end-to-end прогон визарда сознательно **не выполнялся** (нет изолированного стека MySQL+PHP 8.3; требование «не выполнять установку» соблюдено).
- **Невозможно проверить без отдельного сервера/секретов:** поведение на реальном хостинге (Apache rewrite, .user.ini, cron, Supervisor), TLS, доставка почты, боевые вебхуки YooKassa, статус GitHub Actions, время выполнения миграций на shared CPU.

**Вердикт:** выпуск «как есть» через установочный визард **заблокирован** (B-1…B-4). Каркас мастера (шаги, реальная PDO-проверка на сервере, идемпотентные миграции/сиды, fail-soft триггер под shared MySQL, bootstrap организации, install.lock, health-check) — здоров и заточен под шаред-хостинг. Доработка локальна (константы ENV_TEMPLATE, версия PHP, условие сидера, MAIL-шаг, реальный AJAX test-connection) и не требует архитектурных изменений.
