# NABILET — безопасность, домен, git/деплой

Вынесено из `MEMORY.md`, чтобы тот влезал в инъекцию. Детали — `SECURITY-AUDIT-2026-10-10.md`,
`docs/DATABASE.md`, `docs/ARCHITECTURE.md`, `docs/UI-DESIGN.md`, логи `2026-10-0X.md`.

## Безопасность
- `POST /payments` без `idempotency_key` (тело или заголовок `Idempotency-Key`) не создаёт платёж: 201 = создан, 200 = повтор. Ветка «тот же ключ + заказ» ВЫШЕ проверки активного платежа, иначе повтор после `succeeded` = 500 на UNIQUE `uq_payments_idempotency`. Ключ валидируется в сервисе, не только в контроллере.
- Пароли — только `Auth\Validation\PasswordRules` (`uncompromised()` при `NABILET_PASSWORD_BREACH_CHECK=true`). Пустой/`null` = «не менять»: `Rule::excludeIf` убирает ключ из `validated()`, иначе `update(['password'=>null])` пишет ПУСТУЮ строку в NOT NULL. `Rule::excludeIf()` зовёт условие БЕЗ аргументов — значение читать из `request()`.
- LIKE-фильтры только через `Core\Support\LikePattern`. Медиа-allowlist `MediaController::ALLOWED_TYPES` без `image/svg+xml` (stored-XSS). `ProductionSafetyProvider` (только `production`) падает на `APP_DEBUG=true`, `PAYMENT_DEMO_MODE=true`, `TRUSTED_PROXIES=*`, `CACHE_STORE=database`, `*` в IP-allowlist вебхуков. `StoreOrderRequest` НЕ объявляет `items.*.price` — цена всегда из `inventory_items.price_amount`.

## Домен
- `2026_10_06_000100_harden_hall_schema_lifecycle` может упасть на MySQL 1419 — накатывать через `--path`. Геометрия принадлежит ВЕРСИИ схемы; устаревшая в продаже → `ConflictError SCHEMA_GEOMETRY_IN_USE` (409). Konva-холст не выносить без `vi.mock('konva')`.
- `TicketTemplateResolver` — макет от `tickets.event_id`→`session_id`→`sessions.event_id`, НИКОГДА от пользователя. Свой QR (`Tickets/Domain/Qr/`): эталон `tests/Fixtures/qr_golden.json`; явный `mode:'byte'`, в `(8,0)` СТАРШИЙ бит формата, N4 = `|ceil(доля%/5)−10|`; подпись — `QrSignerFactory`, фолбэка на `APP_KEY` НЕТ; `qr_data_uri` + текстовый payload рядом (Gmail режет `data:`).
- Медиа: маппинг — ТОЛЬКО `app/Modules/Media/Models/`; диск из `nabilet.media.disk` (default `public`), НЕ `filesystems.default`. Удаление мягкое + связи снимать ЯВНО. `php artisan storage:link` обязателен (иначе 403). Новые app-only таблицы → в ОБА списка `tools/verify-migrations.php`.
- Отложено (D2): генерируемая колонка `IF(status IN ('issued','used'), seat_id, NULL)` + правка `nabilet_core_spec/migrations.sql`.

## Резервное копирование (FINAL-ACCEPTANCE B-1/B-2)
- `backup:run` в расписании (`routes/console.php`) И команда зарегистрирована в
  `bootstrap/app.php::withCommands()` — неизвестная команда валит весь `schedule:run`.
- `YandexDiskBackupService` — пространство имён `Nabilet\Modules\Backups\*` (было
  `App\Modules\Backups\*`: composer находил, а dependency-free `tests/autoload.php` — нет).
  Пароль уходит файлом `[client]` через `--defaults-extra-file` (0600, удаляется в `finally`),
  НЕ аргументом `-p`. Чистые статические `buildDumpCommand()`/`credentialsFileContents()`
  закреплены `tests/Unit/YandexDiskBackupServiceTest.php`.

## Git, деплой, окружение
- Админка (Vue): новые разделы только на `NCard`/`NPageHeader`; NDataTable — уже карточка; `lib/theme.ts` строит шкалу brand/accent в рантайме, статусные mint/rose/sun/sky НЕ менять. Headless-проверка — `.shots/admin-ui-check.mjs`.
- GitHub только SSH (`git@github.com:fegerV/nabilet`), ключ `~/.ssh/id_ed25519_nabilet`, ssh `C:/Program Files/Git/usr/bin/ssh.exe`.
- Push/fetch через HTTP CONNECT-туннель: `~/.ssh/config` для `github.com` → `ProxyCommand ... http_connect_tunnel.py %h %p 127.0.0.1:<PORT>`; порт из `$HTTPS_PROXY` текущего сеанса (меняется КАЖДЫЙ сеанс — синхронизировать перед fetch/pull/push).
- **Рабочая команда обхода устаревшего порта** (в `~/.ssh/config` он зашит от прошлого сеанса →
  `ConnectionRefusedError [WinError 10061]`; сам `~/.ssh/config` НЕ править — порт сменится снова).
  Порт брать из `env | grep -i https_proxy`, подставить вместо `63323`:
  ```bash
  PY="C:/Users/Professional/.workbuddy-ai/binaries/python/versions/3.13.12/python.exe"
  export GIT_SSH_COMMAND="ssh -o 'ProxyCommand=$PY C:/Users/Professional/.ssh/http_connect_tunnel.py %h %p 127.0.0.1:63323' -i C:/Users/Professional/.ssh/id_ed25519_nabilet -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
  git fetch origin main   # затем push
  ```
  Проверка живости порта: `netstat -ano | grep LISTENING | grep :<PORT>`.
  **Порт туннеля меняется между сессиями** (живой: 63323 → 57822 → 57641 → **65528**, 2026-10-20).
  Симптом устаревшего порта: `fatal: Connection closed by UNKNOWN port 65535`. Лечение: найти
  LISTENING-порт перебором `CONNECT github.com:443` (ответ `HTTP/1.1 200 Connection Established`)
  и вписать его в `ProxyCommand` — либо в `~/.ssh/config`, либо через `GIT_SSH_COMMAND`.
- **Если `$HTTPS_PROXY` в сеансе ПУСТ** (случилось 2026-10-19 днём) — порт находится перебором
  LISTENING-портов прогоном `CONNECT github.com:443` (python `socket`): живой отвечает
  `HTTP/1.1 200 Connection Established`, был найден **57641** (зашитый в конфиг 57822 — refused).
  Кандидаты — все `127.0.0.1:5xxxx`, что висят в `netstat`; `CODEBUDDY_SERVICE_PROXY_URL` и
  порт MCP connector-proxy из `CODEBUDDY_MCP_CONFIG` туннелем НЕ являются.
- **Чужой незакоммиченный WIP + уехавший origin/main → rebase только через временный worktree.**
  `git rebase --autostash` в основном дереве НЕЛЬЗЯ: в репо бывает активная вторая сессия с
  десятками изменённых файлов, и почти все они пересекаются с входящими коммитами → `stash pop`
  конфликтует. Схема: `git worktree add --detach <temp> origin/main` → `git cherry-pick <sha>` →
  `git push origin HEAD:main` → `git worktree remove`. Локальную ветку `main` НЕ двигать:
  `git update-ref`/`git reset` разъедутся с индексом и покажут чужому сеансу сотни «изменённых»
  файлов. В репо есть и ЧУЖОЙ worktree `C:/Project/nabilet-push` (`sync-ticket-fixes`) — не трогать.
- `public_html/build/` отслеживается НАМЕРЕННО (`docs/deploy-timeweb.md:41`: на шаред-хостинге
  нет npm, ассеты приезжают вместе с кодом). `git rm --cached` по нему = сломать деплой.
- **Артефакт деплоя витрины — `public_html/` (не `dist/`, `dist/` в `.gitignore`).**
  Но сам собранный HTML/ассеты в `public_html/` НЕ обновляются коммитами витрины с 2026-10-20
  (решения про покупку в один экран) — это локальный артефакт выкладки, обновление делает
  шаг деплоя, а не `vite build`. Не считать это «забытым файлом» в `git status` и не
  коммитить вручную вместе с исходником.
- **`vendor/` (11 429 файлов) и `node_modules/` (7 198) в индексе — ВОПРЕКИ документации.**
  `docs/deploy-timeweb.md:130` («`composer install` обязателен — `vendor/` не коммитится»,
  шаг 2 деплоя) и `README.md:194` («в репозитории нет `vendor/`») говорят обратное, и деплой-док
  написан ПОЗЖЕ последнего заноса (2026-10-07 против 2026-10-05). Плюс `node_modules/` стоит
  первой строкой `.gitignore` — то есть игнорируется и всё равно отслеживается. История:
  `4ad00ff7`/`cb787b74`/`9c547ee0` — purge-коммиты, `+vendor/` в `.gitignore` добавлялось 22 раза
  и удалялось 23; обратно занесли «Add Filament ... dependencies» (15 285 файлов), после
  вырезания Filament (`23b3a0d0`) осталось 11 427. НЕ тронуто: `git rm -r --cached vendor`
  + пуш → `git pull` на Timeweb удалит `vendor/` на сервере. Нужно решение владельца.
  Прежняя редакция этой заметки объявляла все три каталога намеренными — это неверно.

## CI: что где проверяется и как читать падения (2026-10-11)
- Схема живёт в **ТРЁХ** копиях: канон `nabilet_core_spec/migrations.sql` (его читают оба
  локальных гейта — `verify-migrations.php`, `verify-models-schema.php`); сплит
  `nabilet_core_spec/migrations/001..012.sql` (локально НЕ проверяется никем, сверяет только
  CI дифом `information_schema.columns`); копия в корне `migrations.sql` (нужна ТОЛЬКО CI и
  уезжает в образ через `COPY . .`). Генератора нет ни у одной — `tools/gen-migrations.py`
  делает только `database/migrations/*.php`. Корень ОБЯЗАН быть побайтово равен канону
  (`cp nabilet_core_spec/migrations.sql migrations.sql`; CI проверяет шагом `cmp`).
  Общая устарелость двух копий делает их взаимный диф бесполезным — именно так сплит прожил
  с устаревшими `uq_events_org_slug`/`uq_venues_org_slug` и без `events.ticket_template_id`.
- Job `schema` ассертит `EXPECT_TABLES=73` / `EXPECT_COLUMNS=785` / `EXPECT_TRIGGERS=1`.
  При росте схемы править в ТОМ ЖЕ коммите.
- **Логи Actions недоступны без прав** (`GET /actions/jobs/{id}/logs` → 403 «Must have admin
  rights»), HTML страницы job'а логов не содержит. Но текст ошибки отдаётся ПУБЛИЧНО:
  `GET /repos/fegerV/nabilet/commits/{sha}/check-runs` → `GET /repos/fegerV/nabilet/check-runs/{id}/annotations`.
  `gh` в песочнице нет; внешние запросы — `curl --ssl-no-revoke`.
- Job `phpunit` копирует `.env` из `.env.example` → суита обязана быть герметичной. Форсится
  в `phpunit.xml`: `DB_*`, `MAIL_MAILER`, `TICKET_QR_SECRET`. `force="true"` **перезаписывает**
  `$_ENV` (`PhpHandler::handleEnvVariables()`: `if ($force || !isset($_ENV[$name]))`), поэтому
  `VAR= php vendor/bin/phpunit` CI не воспроизводит — форс побеждает переменную шелла.
  Воспроизводить подменой `.env`: `cp .env.example .env` + 5 `sed` по `DB_*` + `key:generate`.
- **Код возврата PHPUnit различает класс поломки:** `2` — ошибки исполнения (исключение),
  `1` — упавшие утверждения. Run 292 = 2, run 293 = 1: уже по этому видно, что предыдущая
  правка сработала, а осталось другое. Номер строки в аннотации job'а **врёт** — шаг искать
  в `actions/runs/{id}/jobs` → `steps[].conclusion`.
- Страница события отдаётся как `dist/index.html` — артефакт сборки, в git его нет
  (`.gitignore: /dist`), и job `phpunit` Vite не запускает. Поэтому `tests/TestCase::setUp()`
  ставит `nabilet.storefront.shell_path` = `tests/Fixtures/storefront-shell.html`; контракт
  настоящей сборки (`<title>`, `<meta name="description"`, `</head>`, `<div id="app"></div>`)
  грепает job `frontend` после `npm run build`. Посадочные `/venue`, `/city` оболочку НЕ читают
  (свой документ) — `config(['nabilet.storefront.shell_path' => …])` в `Landing*Test` = no-op.
  Инъекция в `renderSpaShell()` обязана ограничиваться первым вхождением (`limit 1`): иначе
  упоминание тега в комментарии оболочки дублирует canonical/og/JSON-LD.
- `Dockerfile` собирается в CI (`Container files` → `docker build`). База —
  `php:8.4-fpm-alpine`: 14 production-пакетов (линия Symfony 8) требуют `php >=8.4.1`,
  на 8.3 `composer install` отказывает («lock file does not contain a compatible set of
  packages», exit 2).
- **Локально образ собрать НЕЛЬЗЯ:** движок Docker 18.09.2, `apk add` и
  `docker-php-ext-install` падают на `tar: Cannot change mode ... Operation not permitted`.
  Но `pull`/`run`/`cp`/`exec` работают — шаги Dockerfile проверяются по отдельности
  (`docker run` + `docker cp` манифестов + composer из сети). `-v <файл>` создаёт КАТАЛОГ
  (использовать `docker cp`); Git Bash калечит пути в контейнере → `MSYS_NO_PATHCONV=1`.
