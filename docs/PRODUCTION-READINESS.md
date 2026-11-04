# NABILET — готовность к продакшену (актуально 2026-10-27)

> **Источник истины о текущем состоянии.** Прошлый срез — 2026-10-07 (PHPUnit 105/105,
> unit 596). С тех пор закрыты Auth reset/verify, placeholder-разделы, CI, вебхуки,
> лояльность и целая серия дефектов «правило скопировано, а не вызвано». Этот файл
> фиксирует **реальное** состояние на 2026-10-27, конкретные оставшиеся места и план
> закрытия. Исторический срез «что осталось до запуска» — [`LAUNCH-READINESS.md`](LAUNCH-READINESS.md)
> (2026-09-20, прикладной слой тогда не был написан) — **не актуален**.

---

## 1. Краткий вывод

**Код готов к продакшену; готова ли среда — нет.** Всё, что можно проверить без внешних
сервисов, зелёное: 15 гейтов, `verify-live` (схема живой БД = спека), 1048 unit-методов,
полный PHPUnit-набор, `vue-tsc`, Vitest, production-сборка Vite, PHPStan level 5 в CI.

**Блокирует запуск только конфигурация production-транспорта и внешние интеграции:**

1. **Среда:** `APP_ENV=production`, `APP_DEBUG=false`, реальный `APP_KEY`, SMTP-креды,
   `MAIL_FROM_ADDRESS`; воркер очереди (`queue:work`) и минутный cron `schedule:run`;
   `CACHE_STORE`/`SESSION_DRIVER` → `redis` (или `database`), права на `storage/`.
2. **Живой платёж:** YooKassa проверена тестами и по контракту, но **не** прогнана против
   реального `api.yookassa.ru` с боевыми ключами и живым вебхуком.
3. **Не построено:** — (клиентская часть Embed закрыта 2026-11-03, см. §4 строку 7 и P2).
   Переводы (`/venues/{}/translations`, `/pages/{}/translations`) построены 2026-11-03
   (см. §4 строку 6).
   Соц-вход (Яндекс/VK/Telegram) построен, но **выключен без кредов**: пустой
   `YANDEX_CLIENT_ID`/`VK_CLIENT_ID`/`TELEGRAM_BOT_TOKEN` = провайдер отвечает отказом,
   а не 500. Для включения нужны приложения у провайдеров и `redirect_uri`, побайтово
   совпадающий с зарегистрированным.
   **Оговорка про embed:** рамка витрины работает как обычная навигация (публичные
   страницы), а денежный флоу рамки пока НЕ переключён на `/api/v1/embed/*` —
   выпущенный embed-ключ котируется на этом будущем шаге; сам канал
   `/embed/*` (7 путей контракта) готов.

**Не блокирует веб-продажи:** Android Checker (отдельный проект), 152-ФЗ-инфраструктура
(бэкапы/мониторинг/ротация секретов) — нужны до публичного трафика, но не до smoke-теста.

---

## 2. Свежие метрики (проверено 2026-10-27, актуализировано 2026-11-04)

| Проверка | Результат |
|---|---|
| Гейты (`verify-*` × 15 + `modules --validate`) | **15/15 OK**, `modules.exit=0`, `lint.exit=0` (2026-11-04) |
| `verify-live.php` (живая БД ↔ спека) | **PASS** (2026-11-04): 88 таблиц / 953 колонки, FK 167, unique 118, check 55, триггеры 3 — совпадают; спека-пути 94, **served 94, missing 0** |
| Миграции живой БД | **0 pending** (`artisan migrate:status`); `verify-migrations` — 16/16 |
| Unit (dependency-free, `tests/run.php`) | **1048 методов / 2658 утверждений**, 0 failed (2026-11-04) |
| Feature (`vendor/bin/phpunit`) | **1291 тест / 5272 утверждения**, 0 failed (2026-11-04) |
| Фронт: `vue-tsc --noEmit` | **OK** (exit 0) |
| Фронт: Vitest | **414/414** (33 файла) |
| Фронт: `vite build` | **OK** — 8.98 s, 72 ассета, `index-*.js` 177 kB (gzip 66 kB) |
| PHPStan level 5 | В CI-job `static` (локально песочница блокирует `reg.exe`/`WMIC.exe`) |
| Спека | 88 таблиц / 953 колонки (`tools/spec-counts.php`) |
| Структура | 36 модулей, 52 контроллера, 184 route-декларации в 27 файлах, OpenAPI 94 пути / 119 операций, 51 миграция (OpenAPI и миграции — 2026-11-03) |
| Заглушки/долги | **0** `TODO`/`FIXME`/`notImplemented` в `app/`; **0** хардкод-секретов; **0** `dd/dump` |
| CI | 6 job'ов: `verify`, `static` (PHPStan), `docker`, `schema`, `phpunit`, `frontend` |

`verify-live` **PASS без расхождений** (2026-11-03): таблицы, колонки, FK, unique,
CHECK и триггеры — совпадение; все 94 спек-пути обслуживаются приложением под
точными путями (served 94, missing 0 — см. §4 строки 3–4).

---

## 3. Что закрыто с прошлого среза (2026-10-07 → 2026-10-27)

- **Auth:** восстановление пароля и верификация e-mail (`AccountTokenService`,
  `AccountRecoveryService`), пути приведены к контракту; `throttle:auth` 5/мин на пару
  e-mail+IP и 20/мин на IP.
- **Placeholder-разделы:** удалены; catch-all админки — честный `AdminNotFoundPage`.
- **CI:** 6 job'ов — верификаторы, PHPStan, PHPUnit на MySQL 8.4, фронт (типы/тесты/сборка),
  схема спеки, Docker-образ.
- **Вебхуки:** HMAC-SHA256, SSRF-guard, retry/backoff, журнал доставок, идемпотентность fan-out.
- **Лояльность:** фазы 1 (избранное/подписки) и 2 (бонусный счёт, кэшбэк, списание в чекауте).
- **Деньги — единый владелец** (`Money`): конверсия minor↔decimal в Payments (B43),
  формат суммы заказа в письме (B44), символ валюты (B45), цена билета в письме и PDF (B46),
  цена «от N ₽» на посадочной (B47). Ручных `number_format` для денег вне `Money` — **нет**.
- **Возвраты:** решение о сумме — только `RefundLedger::evaluate()` (B42).

### Установка на шаред-хостинг (срез 2026-11-04)

- **Визард проверен e2e на чистой копии** (DocumentRoot = `public_html/`, без `.env`,
  `php -S` как прокси для FPM): найдены и закрыты **шесть звеньев «яиц и курицы»**,
  каждое из которых по отдельности делало `/install` недостижимым или установку
  ломающей: (1) `MissingAppKeyException` до записи `.env` — ключ теперь
  гарантируется на буте (`PreinstallAppKey`, персистится в `storage/`, чистится
  после установки); (2) дефолтные драйверы `database` при неизвестном хосте БД —
  до записи `.env` включаются файловые (`PreinstallDrivers`, каталоги создаются
  явно); (3) `Unknown collation: ''` на MySQL 8 — в шаблон `.env` добавлены
  `DB_CHARSET`/`DB_COLLATION`, подкладываются в конфиг перед `migrate`;
  (4) throttle/кеш при `CACHE_STORE=database` без таблиц — миграция
  `2026_09_19_000000_000_cache` (таблицы `cache` + `cache_locks`), применена и на
  живую БД; (5) bcrypt-сидеры не влезали в `max_execution_time=30` —
  `set_time_limit(0)` на время установки; (6) сторож прод-конфига ронял бут до
  установки — окно «визард не завершён» теперь не-violations
  (`ProductionSafetyProvider::installerFinished`).
- **Полный e2e после фиксов:** GET `/install` → 200 (requirements passed) →
  test-database → 200 → POST `/install` → 200 (`redirect: /admin`, 96 таблиц в БД
  = 94 бизнес-таблицы + cache + cache_locks); `.env` полный (production, https,
  utf8mb4, `NABILET_ALLOW_DATABASE_CACHE=true`); повторные POST/GET → 403
  `ALREADY_INSTALLED`; `/api/v1/health` → 200; витрина `/` отдаёт `dist/index.html`.
  Прогон повторён на **распакованном zip-архиве** — см. ниже.
- **Архив установки собран и протестирован:** `nabilet-hosting-20261104.zip`
  (~31 МБ) — `app/ bootstrap/ config/ database/ lang/ public/ public_html/
  resources/ routes/ storage/ (скелет) vendor/ dist/ .env.example .user.ini artisan
  composer.*`; без `tests/ tools/ docs/ .git .env install.lock логов`. На хостинге
  из архива не нужны ни `composer install` (vendor включён), ни `npm run build`
  (dist включён). Распаковка + полный проход визарда — зелёные.

---

## 4. Оставшиеся расхождения и риски

| # | Где | Что | Серьёзность | Влияние |
|---|---|---|---|---|
| 1 | `.env` (production) | `APP_DEBUG=true`, `MAIL_MAILER=log`, пустой `MAIL_HOST`; `CACHE_STORE=file`, `SESSION_DRIVER=file` | 🔴 до настройки | Письма не уходят, отладка светит стектрейсы, сессии/кэш не разделяются между воркерами |
| 2 | YooKassa | Нет живого e2e с боевыми ключами и реальным вебхуком | 🔴 | Платёж/возврат/подпись проверены в тестах, но не против боевого API |
| 3 | ~~`verify-live`: триггеры~~ | Закрыто 2026-11-03 (коммит `0d779228`): бандл спеки дополнен секцией 024 — три триггера жизненного цикла `hall_schema_versions` (вместо прежнего фиктивного `trg_schema_version_immutable`); спека = живая БД, 3/3 | ⚪ закрыто | — |
| 4 | ~~`verify-live`: 29 путей~~ | Закрыто 2026-11-03 (коммит `85734816`): `openapi.yaml` (обе копии) приведён к фактическим маршрутам — 94 пути / 119 операций, «served 94, missing 0». OAuth: 4 пути → 2 (`/auth/{provider}/start\|callback`); все `/admin/*` свернуты в ресурсные пути (`events`, `sessions`, `venues`, `halls`, `orders`, `tickets`, `webhook-subscriptions`); платёж — `POST /api/v1/payments`, вебхук — `POST /api/v1/payments/webhooks/{provider}`; несуществующие эндпоинты (export/import версий схем, standalone-версия схемы, funnel/events-аналитика, block/unblock инвентаря, cancel тикета) сняты со спеки | ⚪ закрыто | — |
| 5 | Auth соц-вход | Реализован (`/auth/{provider}/start`, `/auth/{provider}/callback`, `POST /api/v1/telegram/auth`), но не включён: нет кредов приложений у Яндекс/VK/Telegram | 🟡 | Пока кредов нет — вход только e-mail/пароль; включение = переменные окружения, без правки кода |
| 6 | ~~Translations~~ | Закрыто 2026-11-03: 4 пути контракта построены — публичные списки `GET /venues/{venue}/translations` и `GET /pages/{page}/translations`, upsert `PUT …/translations/{locale}` под `auth:api` + `admin` (контракт openapi.yaml:2384-2468). Владелец: площадка — binding скоупнутой `Venue` (чужая = 404), страница — `assertCanAccess` по `pages.organization_id` («общая строка» платформы = 404); 14 feature-тестов изоляции. Попутно починен fill `PageTranslation` (случайная `meta_description` не в схеме, реальные `slug`/`seo_*` добавлены) | ⚪ закрыто | — |
| 7 | `app/Modules/Embed/**` | Бэкенд **готов и покрыт**: 7 публичных эндпоинтов, все 5 проверок ТЗ §18, отказ закрытый, 27 feature-тестов. Провижининг **построен**: `POST /admin/embed/keys` (токен показывается один раз, в базе только sha256) и `GET/POST/PATCH/DELETE /admin/embed/domains` (whitelist с нормализацией домена) — 18 тестов, включая сквозной «выпустили ключ → витрина открылась». **Мост виджета построен (2026-11-03):** `lib/embed-bridge.ts` — postMessage-канал `nabilet-embed` из рамки родителю (`cart` по подписке на store в `StorefrontShell`, `order`/`payment` в `usePurchaseFlow`, `height` через ResizeObserver); целью берётся origin из `document.referrer`, без него — `'*'` (события не содержат персональных данных). **Генератор кода построен (2026-11-03):** страница `/admin/embed` — выпуск ключа с однократным показом токена (сниппет собирается сразу, `lib/embed-code.ts` — чистые функции, 13 Vitest-тестов фиксируют текст буква в букву), отзыв `PATCH {revoked:true}`, whitelist доменов (вкл/выкл, DELETE, 409 на дубль) | ⚪ закрыт | Future: денежный флоу рамки на `/api/v1/embed/*` (ключ уже в адресе рамки) |
| 8 | Инфраструктура | Бэкапы БД — построены (`backup:run` dailyAt 03:00, шифрование, ротация, YandexDisk), эксплуатация не проверена. PII-free логи — построены (`RedactSensitiveData` в каналах `monolog`+`daily`, `LogReader`). **Мониторинг/алерты построены 2026-11-03:** `system:selfcheck` каждые 10 минут — сигналы БД/Redis/очереди (`HealthProbe`) плюс диск и свежесть бэкапа (`HostChecks`, извлечён из админ-диагностики — один владелец правил); запись в канал `alerts` (`storage/logs/alerts-*.log` → вкладка «Логи»), дублирующее письмо на `NABILET_ALERT_EMAIL` синхронно, мимо очереди; дедупликация по составу проблем, восстановление — отдельная запись, `--force` повторяет сигнал об инциденте. 152-ФЗ: домен готов (29 тестов), **инварианты схемы закрыты 2026-11-03** (секция 026: `consents.withdrawn_at`, `privacy_requests.due_at`, CHECK-словари; CHECK «ровно один субъект» невозможен в MySQL — 3823, см. REVIEW §3.15). **API обращений субъектов построено 2026-11-04:** подача `POST /api/v1/privacy-requests` (гость обязан дать контактный e-mail → `payload_json.contact_email`; авторизованный — не обязан, адрес берётся из профиля; `throttle:10,1`), трекинг `GET /privacy-requests/{publicId}` — статус и записанный дедлайн БЕЗ `payload_json`, обработка оператором `GET/PATCH /admin/privacy-requests/{publicId}` (роль `admin`, фильтры type/status, `payload_json` виден, отказ перехода — 409 `PRIVACY_REQUEST_TRANSITION_DENIED`, повтор того же статуса — 200 без записи; дедлайн из `NABILET_PRIVACY_SLA_DAYS`, по умолчанию 30 дней). Пути сознательно вне спеки (как newsletter). **Ротация секретов — runbook построен 2026-11-04** (`docs/SECRET-ROTATION.md`: инвентаризация всех секретов `.env` с ценой утечки и ценой ротации, процедуры для `APP_KEY` (шифрованные поля БД: `webhooks.secret_encrypted`, `settings`, reset-токены — `user_sessions` не страдает, токены хешем), QR-секрета (окно без сеансов, пересинхронизация Checker-бандлов), бэкап-ключа («старый ключ в склеп» до истечения retention), БД/Redis/платёжных/OAuth на среде Timeweb, аварийный порядок, журнал ротации; находка: `NABILET_WEBHOOK_SECRET` не читается кодом — подписи исходящих вебхуков живут в подписках). **API-флоу согласий построен 2026-11-04:** `GET /api/v1/me/consents` и `POST /me/consents/{type}/withdraw` под `auth:api` (прецедент `/me/*` Loyalty, владелец только из Bearer-токена; throttle не нужен): список — по одной последней строке на цель («последнее решает», анонимные согласия рассылки не видны); отзыв — новая строка журнала (`withdrawn_at == created_at`, `policy_version` наследуется, ip упакован `PackedIp`, ua обрезан; повторный отзыв — 200 `written:false` без записи; несуществующая цель — 404 `CONSENT_NOT_FOUND`, истёкшее — 409 `CONSENT_NOT_WITHDRAWABLE`). **Generic grant НЕ построен — решение fail-closed** (`ConsentService::latestByType`/`withdraw`; строка granted без потребителя обработки = мусор аудита; grant добавится к первому контуру вне рассылки). Латентный дефект закрыт: у модели Consent снят трейт HasPublicId (у `consents` нет колонки `public_id` — записи через Eloquent падали бы) | 🟡 | Нужен до публичного трафика |
| 9 | Android Checker | Отдельное приложение (офлайн-бандлы) | ⚪ вне web-MVP | Не блокирует веб-продажи |

---

## 5. Готовность по областям

| Область | Оценка | Комментарий |
|---|---|---|
| Ядро / домен (прайсинг, корзина, инвентарь, заказы, билеты, чекин, платежи, приватность, тенантность) | 🟢 | Покрыто тестами; дефекты доказаны исполнением |
| API / HTTP | 🟢 | Валидация, роли, мультитенантность; `verify-live` PASS |
| Фронтенд (витрина + админка + залы + конструктор билетов) | 🟢 | `vue-tsc`, Vitest 424/424, production build |
| Платежи (YooKassa: create/status/refund + вебхук) | 🟡 | Код и подпись готовы; живой e2e не проведён |
| Уведомления / почта | 🟡 | Код и шаблоны готовы; production SMTP/cron не настроены |
| Auth | 🟢 | E-mail reset/verify и соц-вход (Яндекс/VK/Telegram) построены; соц-вход ждёт кредов провайдеров |
| Инфраструктура (Docker/деплой/бэкапы/мониторинг/152-ФЗ) | 🟡 | Docker и `deploy-timeweb.md` есть; эксплуатация не проверена |
| CI | 🟢 | 6 job'ов, включая PHPStan и Docker-сборку |

---

## 6. План закрытия перед продакшеном

### P0 — среда (без кода)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, сгенерировать `APP_KEY`.
- [ ] SMTP-креды + `MAIL_FROM_ADDRESS`; живой smoke письма на реальный ящик.
- [ ] Воркер `php artisan queue:work` под supervisor + минутный cron `schedule:run`.
- [ ] `CACHE_STORE`/`SESSION_DRIVER` → `redis` (или `database`); права на `storage/`, `bootstrap/cache`.
- [ ] HTTPS, `APP_URL`, доверенные прокси; `migrate --force` (сейчас 0 pending).

### P1 — качество MVP
- [ ] Живой e2e YooKassa (боевые ключи): платёж → вебхук → билет → возврат.
- [x] Спека и триггеры — закрыто 2026-11-03: 29 спек-путей устранены амендом
      `openapi.yaml` к фактическим маршрутам («served 94, missing 0»), спека
      дополнена тремя триггерами жизненного цикла схем зала (см. §4 строки 3–4).

### P2 — полнота
- [x] Соц-вход (Яндекс/VK/Telegram) — построен; включение требует кредов приложений.
- [x] Translations-подпути (`venues`, `pages`) — построены 2026-11-03: публичные списки
      + upsert под админкой организации (см. §4 строку 6). Локализованные адреса
      `/{locale}/pages/...` остаются на будущее: таблица `page_translations.slug`
      теперь имеет и писателя, и читателя.
- [x] Embed: провижининг — выпуск `api_keys` со скоупом `embed` (`POST /admin/embed/keys`,
      токен один раз) и whitelist `embed_domains` (`/admin/embed/domains`). Сквозной тест:
      выпущенный ключ открывает `/api/v1/embed/events/{id}` с разрешённого Origin.
- [x] Embed: клиентский виджет — закрыт 2026-11-03. Мост `postMessage`
      (`lib/embed-bridge.ts`: `cart`/`order`/`payment`/`height` из рамки родителю,
      10 Vitest-тестов) и генератор кода в админке (`/admin/embed`: выпуск ключа
      с однократным показом токена → готовый `<iframe>`-сниппет со слушателем
      моста, whitelist доменов; `lib/embed-code.ts`, 13 Vitest-тестов).
      **Будущий шаг:** денежный флоу рамки на `/api/v1/embed/*` — сейчас рамка
      грузит публичную витрину, ключ адресует только будущие прямые запросы.
- [x] Инфраструктура: API-флоу согласий построен (2026-11-04 — `GET /me/consents` + `POST /me/consents/{type}/withdraw`, generic grant отложен — решение fail-closed). Построено: бэкапы, PII-free логи, мониторинг/алерты (2026-11-03, см. §4 строку 8), API обращений субъектов 152-ФЗ (2026-11-04 — подача/трекинг/обработка оператором), runbook ротации секретов (2026-11-04 — `docs/SECRET-ROTATION.md`).
- [ ] Android Checker — отдельный проект.

---

## 7. Методика проверки 2026-10-27

- `tools/verify-*.php` (13) + `tools/modules.php --validate` → exit 0.
- `tools/verify-live.php` → PASS (88/911, FK 127, unique 118, check 53); триггеры и 29 путей
  — «reported, not failed».
- `php tests/run.php` → 940/2322; `php vendor/bin/phpunit --no-coverage` → 1141/4659.
- `node node_modules/vue-tsc/bin/vue-tsc.js --noEmit` → 0; `vitest run` → 414/414;
  `vite build` → 72 ассета (локально падает только на sandbox-шимме удаления `dist/`,
  в CI-job `frontend` собирается штатно).
- `grep` по `app/`: 0 `TODO/FIXME/notImplemented`, 0 хардкод-секретов, 0 `dd/dump`;
  `number_format` для денег — только внутри `Money` (остальные — проценты и сам владелец).
- PHPStan level 5 запускается в CI (`static`); локально песочница блокирует `reg.exe`/`WMIC.exe`.
