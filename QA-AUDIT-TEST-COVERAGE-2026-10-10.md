# QA-аудит тестового покрытия Nabilet Core

**Дата:** 2026-10-10
**Роль:** QA Automation Architect
**Объект:** репозиторий `/workspace` (коммит `3f8d9912`, merge PR #105)
**Метод:** инспекция тестов, конфигурации, CI-workflow; фактический запуск всех проверок, возможных в данной среде. Тесты и приложение во время аудита **не изменялись**.

---

## 0. Резюме (TL;DR)

Проект имеет необычно зрелый для своей стадии набор **статических верификаторов-ратчетов** (12 инструментов в CI, все зелёные), сильную прослойку **framework-free unit-тестов доменной логики** (684 метода, проходят без Composer/БД) и продуманный слой **Feature-тестов против реальной MySQL-схемы** (67 PHP-файлов, изоляция через `RefreshDatabase`, фейки HTTP/mail).

Однако способность автоматически обнаруживать регрессии ограничена пятью системными пробелами:

1. **Нет E2E-теста сквозного оформления заказа** (cart → checkout → payment → ticket на уровне HTTP/UI);
2. **Нет теста конкурентного бронирования** — инвариант «место не может быть продано дважды» при параллельных транзакциях проверяется только однопоточно на уровне домена;
3. **Инфраструктура Feature-тестов не исполняема локально** (phpunit требует PHP ≥ 8.4.1, здесь 8.2.34; MySQL недоступен) — главный регрессионный слой подтверждён только косвенно;
4. **PHPStan и Pint существуют как зависимости, но не запускаются ни в одном workflow** — статический анализ фактически мёртв в CI;
5. **Нет проверок безопасности зависимостей** (`composer audit`, `npm audit`, Dependabot/Renovate отсутствуют; `npm ci --no-audit`).

Изоляционная гигиена тестов в целом хорошая: реальная платёжная система и SMTP исключены (`Http::fake`, `MAIL_MAILER=array` force), случайность ограничена суффиксами `Str::random` для уникальных slug, порядок запуска не значим (`RefreshDatabase`, нет `@depends`). Слабое место — **время**: ~87 обращений к `now()/Carbon::` при **нулевом** использовании `travelTo/setTestNow`; часть доменных тестов передают время явно (детерминированно), но time-based сценарии (hold-expiry, reminder-джобы) частично зависят от системных часов.

---

## 1. Инвентаризация артефактов

| Артефакт | Путь | Комментарий |
|---|---|---|
| PHPUnit-конфигурация | `phpunit.xml` | Только suite `Feature`; DB-force (`nabilet_test_suite`, порт 3307), `MAIL_MAILER=array`, `CACHE/SESSION=array`, `QUEUE=sync` — всё `force="true"`, чтобы shell/CI не перенаправили `migrate:fresh` |
| Runner без зависимостей | `tests/run.php` | 684 unit-метода, 1687 assert; честно печатает список из 31 файла, которые им НЕ запускаются |
| Vitest-конфигурация | `vitest.config.ts` | jsdom, `tests/Unit/**/*.test.ts` + colocated |
| CI | `.github/workflows/ci.yml` | 4 job: `verify` (12 верификаторов + composer validate), `docker` (compose config, hadolint, build), `schema` (MySQL 8.4: применение DDL, счётчики объектов, триггер неизменяемости), `phpunit` (реальная MySQL 8.4 + suite), `frontend` (vue-tsc, vitest, vite build) |
| Статические анализаторы | `phpstan.neon` (level 5), `pint.json` | **Не вызываются в CI** |
| QA-следы | `qa-scripts/*.log`, `AUDIT-*.md`, `SECURITY-AUDIT-*.md`, `SEO-AUDIT-*.md` | Логи миграций, npm build; аудиты платежей/возвратов/инсталлятора |

Размеры набора:
- PHP Feature-тесты: 31 файл (~230 тестовых методов суммарно по `grep -c "public function test"`);
- PHP Unit-тесты (kernel, framework-free): 36 файлов, 684 метода — **подтверждено прогоном**;
- TS/Vue unit-тесты: 18 файлов, 233 теста — **подтверждено прогоном**.

---

## 2. Реальные результаты проверок (замерено в этой среде)

| Проверка | Команда | Результат |
|---|---|---|
| Синтаксис PHP | `php tools/lint.php` | ✅ exit 0 |
| Kernel-тесты (без vendor/БД) | `php tests/run.php` | ✅ **PASS: 684 methods, 0 failed, 1687 assertions, 314 ms**; 31 Feature-файла помечены NOT RUN |
| Чистота домена (нет Illuminate в app/Core) | `tools/verify-purity.php` | ✅ exit 0 |
| Конверт ошибок §66 (ratchet) | `tools/verify-error-envelope.php` | ✅ OK |
| Граф модулей | `tools/modules.php --validate` | ✅ exit 0 |
| Миграции ≡ спецификации | `tools/verify-migrations.php` | ✅ exit 0 |
| Машины состояний ≡ CHECK-ограничения | `tools/verify-state-machines.php` | ✅ exit 0 |
| OpenAPI-контракт (обе копии) | `tools/verify-openapi.php` ×2 | ✅ exit 0 |
| Контракт ≡ схема (ratchet) | `tools/verify-contract-schema.php` ×2 | ✅ exit 0 |
| Модели называют реальные колонки (ratchet) | `tools/verify-models-schema.php` | ✅ exit 0 |
| PSR-4 автозагрузка (ratchet) | `tools/verify-autoload.php` | ✅ exit 0 |
| Структура модулей ≡ реестр | `tools/verify-module-structure.php` | ✅ exit 0 |
| Единственный владелец маршрута | `tools/verify-route-ownership.php` | ✅ exit 0 |
| Vitest (фронтенд) | `npx vitest run` | ✅ **18 files / 233 tests passed**, 31.7 s |
| **PHPUnit Feature-suite** | `vendor/bin/phpunit` | ❌ **НЕ ЗАПУЩЕН**: `platform_check.php` требует PHP ≥ 8.4.1, окружение — PHP 8.2.34; дополнительно отсутствует `pdo_mysql` и сервер MySQL на 127.0.0.1:3307 |
| PHPStan | `vendor/bin/phpstan` | ⚠️ не запускался (тот же platform-check; бинарник установлен, в CI отсутствует) |
| Pint | `vendor/bin/pint --test` | ⚠️ не запускался (то же); в CI отсутствует |
| `composer validate` | локально невозможно | ⚠️ Composer не установлен в среде; в CI присутствует |

**Вывод по результатам:** всё, что можно исполнить без PHP≥8.4/MySQL, — зелёное. Ключевой риск не в том, что тесты падают, а в том, что **главный регрессионный слой (67 Feature-файлов) в данной среде невоспроизводим** и его зелёный статус существует только как обещание CI-джоба `phpunit`.

---

## 3. Матрица покрытия бизнес-сценариев

Легенда: ✅ есть целенаправленные тесты; 🟡 частичное/слабое; ❌ нет.

| # | Бизнес-сценарий / инвариант | Статус | Где доказан | Качество доказательств |
|---|---|---|---|---|
| 1 | Unit: машины состояний (order/payment/refund/ticket/hold) | ✅ | `StateMachineTest`, `DomainStateMachineTest`, + CI-верификатор `verify-state-machines.php` (схема CHECK ≡ код) | Сильное: двойная защита (тесты + статическая сверка с DDL) |
| 2 | Unit: ценообразование, деньги, промо | ✅ | `MoneyTest`, `OrderPricingTest`, `PromoEvaluatorCheckoutBridgeTest` | Хорошее; копейка-точная арифметика (integer kopecks) |
| 3 | Unit: политика идемпотентности | ✅ | `IdempotencyPolicyTest` (18 методов: replay, conflict, in-flight, TTL, scope по организациям, хеширование ключа) | Очень сильное на уровне политики |
| 4 | Unit: выдача/отзыв билетов, QR | ✅ | `TicketIssuanceTest`, `TicketRevocationTest`, `QrSignerTest`, `QrEncoderGoldenMasterTest` (golden master!) | Сильное; golden-master защищает формат QR от дрейфа |
| 5 | Unit: check-in, hold/grace, лимиты | ✅ | `CheckinEvaluatorTest`, `HoldGraceTest`, `InventoryStockTest` (анти-scalping, capacity=1 для места) | Сильное, но однопоточное (см. строку 10) |
| 6 | Интеграция: БД-схема реально принимает записи | ✅ | Все `*WritePathTest` + CI `schema` job (DDL применяется к MySQL 8.4, счётчики 64/684/1, негативные проверки триггера неизменяемости) | Отличное: schema-job — эталон того, как надо тестировать DDL |
| 7 | API: заказы/события/биллеты/media/embed | ✅ | `tests/Feature/Api/*`, `MediaApiTest` (21 метод), `EmbedSecurityTest` (28 метод, fail-closed, чужая организация → 404, а не 403) | Хорошее; tenant-isolation покрыта |
| 8 | Feature: путь заказа (резерв, snapshot цены, отмена освобождает склад) | ✅ | `OrderWritePathTest` (16 методов: цена берётся из inventory, а не из запроса; paid нельзя отменить; partial payment) | Сильное |
| 9 | Feature: платежи и webhook-уведомления | ✅ | `PaymentWritePathTest` (15 методов: succeeded pays+issues ticket, replay×3 ⇒ один capture, failed/canceled терминальны, unknown payment rejected) | Сильное, **кроме подписи** (см. строку 13) |
| 10 | **Конкурентное бронирование мест (гонка)** | ❌ | Нет ни одного теста с параллельными транзакциями/двойным reserve одного seat под нагрузкой; `FOR UPDATE`/атомарные UPDATE в тестах не фигурируют | Критический пробел: инвариант «нет oversell» держится на CHECK + последовательных вызовах |
| 11 | Возвраты (refund) | ✅ (частично) | `PaymentWritePathTest::test_refund_*` (refunds.order_id NOT NULL, статус processing), `PaymentSettlementTest` (11 refund-инвариантов: двойной возврат запрещён, overshoot на 1 копейку отказан, partial→partially_refunded, full завершает заказ) | Хорошее на unit-уровне; нет Feature-прогона полного цикла refund через webhook провайдера против БД |
| 12 | Идемпотентность на HTTP-границе (middleware) | 🟡 | `IdempotencyPolicyTest` — чистая политика; интеграция middleware↔БД (таблица idempotency_keys, гонка двух in-flight) не покрыта end-to-end | Средний риск: история чинила middleware с несуществующими колонками — регрессия здесь возможна |
| 13 | Подпись webhook (HMAC-секрет провайдера) | ❌ | `WebhookSignatureVerifier`/`WebhookAuthenticator` — **ни одного теста**; в `PaymentWritePathTest` секрет всегда `null`, приняты только IP-allowlist и fail-closed | Критический пробел: положительный/отрицательный кейс подписи не проверяется вообще |
| 14 | Роли и доступ (RBAC: roles/permissions/user_roles) | 🟡 | Таблицы RBAC созданы миграцией 001_identity; тесты есть для API-key scopes, embed-origin, tenant-isolation, auth-сессий (`AccountRecoveryTest`: токен сброса одноразовый, rate-limit, forged/expired rejected). Нет тестов per-role авторизации админ-панели (owner vs admin vs staff) | Значительный пробел: роль ≠ scope-API |
| 15 | Уведомления (email-цепочки заказа) | ✅ | `TransactionalMailTest`, `OrderStatusNotificationTest`, `OrderReminderTest` (8), `MailSettingsTest` (SMTP-настройки применяются, пароль не затирается), `TicketEmailTemplateTest` (20) | Хорошее; транспорт `array`, реальная почта исключена |
| 16 | SEO-маршруты (sitemap.xml, sitemap-events/static, robots) | ❌ | Маршруты `Seo/routes/api.php` и `SitemapService` существуют (после SEO-аудита чинили 500 на undefined route) — **тестов нет**; grep по tests не находит sitemap/robots | Пробел с известной историей поломок |
| 17 | Установка и миграции приложения (artisan migrate, installer module) | 🟡 | CI `schema` job исполняет raw DDL; `qa-scripts/step2_migrate_run*.log` — ручные логи; Laravel-миграции выполняются косвенно через `RefreshDatabase`. Модуль `Installer` — тестов нет | Двойственность DDL/миграций покрыта верификатором; сам Installer — нет |
| 18 | E2E оформления заказа (UI/сквозной HTTP-флоу) | ❌ | Playwright/Cypress отсутствуют; cart→checkout есть как сервисные вызовы (`CartWritePathTest::test_checkout_converts_the_cart_into_an_order`), но не как пользовательский сценарий через маршруты + оплата + получение билета | Критический пробел для релизов |
| 19 | Фронтенд unit (stores, geometry, editor, cart) | ✅ | 233 теста vitest — пройдены прогоном | Хорошее; vue-tsc typecheck в CI |
| 20 | Логирование/redaction PII | ✅ | `RedactSensitiveDataTest` (15), `RedactionRulesTest` (16) | Сильное |
| 21 | Health/system | ✅ | `HealthEndpointTest` (8), `HealthEndpointUnreachableDatabaseTest` | Хорошее |
| 22 | Статический анализ (PHPStan level 5) | 🟡 | Конфиг exists, бинарник installed, **в CI не запускается** | Формально заявлен, фактически мёртв |
| 23 | Форматирование (Pint) | 🟡 | Аналогично — конфиг есть, запуска нет | — |
| 24 | Безопасность зависимостей | ❌ | Нет `composer audit`, `npm audit` (явно `--no-audit`), нет Dependabot/Renovate, нет secret-scanning job | Пробел |
| 25 | Сборка и тесты при Pull Request | ✅ | Workflow: `pull_request:` без фильтров; 4 job покрывают верификаторы, docker-build, схему MySQL, phpunit, фронтенд | Хорошее; но см. п.26 |
| 26 | Обязательность (branch protection) | ❓ | Не видна из репозитория; если red-статус не блокирует merge, весь CI — справка | Требует проверки настроек GitHub |

### Покрытие критических бизнес-инвариантов (что реально проверяется)

Проверяются надёжно: цена никогда не берётся из тела запроса; платёж≠двойная оплата при re-delivery webhook; билет с QR выпускается только после успешной оплаты; нельзя оплатить cancelled/expired/refunded заказ; недоплата/переплата отвергаются; двойной возврат и oversell возврата на копейку отвергаются; published hall-schema неизменяема (триггер позитивно+негативно в CI); error body всегда §66-конверт; модели/маршруты/namespace не расходятся со схемой (ratchets).

**НЕ проверяются**: отсутствие oversell при параллельном бронировании одного места двумя покупателями; валидность HMAC-подписи входящего webhook (подделанное уведомление с «правильного» IP будет принято, если allowlist настроен); корректность sitemap/robots на живом роутере; per-role ограничения админ-функций; поведение idempotency-middleware под реальной гонкой.

---

## 4.Внештативные зависимости тестов (flakiness audit) (flakiness audit)

| Фактор | Вердикт | Детали |
|---|---|---|
| Реальная платёжная система | ✅ изолировано | `Http::fake()` в PaymentWritePathTest; YooKassa HTTP-клиент не выходит наружу; CI не содержит платёжных секретов |
| Внешняя почта | ✅ изолировано | `MAIL_MAILER=array force=true` + комментарий в phpunit.xml; AccountRecoveryTest проверяет dispatch, не доставку |
| Реальная БД | ⚠️ контролируемо | Feature-suite требует MySQL 8.4 (порт 3307, `nabilet_test_suite`); disposable by construction (`RefreshDatabase`), DB_* forced. Плюс: воспроизводимость падает вне CI (PHP 8.4 required) |
| Текущее время | 🟡 главный кандидат на flakiness | 0 использований `travelTo/setTestNow`; ~87 упоминаний `now()/Carbon::`. Доменные unit-тесты инжектят время явно (HoldGraceTest, SecurityPolicyTest — детерминированы). Но Feature-сценарии с дедлайнами холдов/reminder-джобами завязаны на системные часы — ночные прогоны у границы TTL теоретически нестабильны |
| Случайные данные | 🟡 приемлемо | `Str::random(6..12)` только для уникальности slug/name в фикстурах; assertions от случайности не зависят. Golden-master QR — наоборот, анти-случаен |
| Порядок запуска | ✅ | Нет `@depends`; RefreshDatabase на каждом тесте; executionOrder не выставлен (дефолт — файловый, стабильный) |
| Окружение разработчика | ❌ | Suite жёстко привязан к 127.0.0.1:3307 root/rootpass; вне этого контура (как в этом контейнере) — не запускается вовсе |

---

## 5. Критические пробелы (ранжировано)

1. **[P0] Нет теста конкурентного бронирования.** Oversell — прямой денежный и репутационный ущерб. Нигде не доказано, что два параллельных `reserve(seat X)` не дадут два hold. Нужен Feature-тест с двумя соединениями/транзакциями (или `proc_open` + curl-заряд) и проверка row-lock semantics в `inventory`.
2. **[P0] Входящий webhook не проверяет подпись в тестах.** `WebhookSignatureVerifier` и `WebhookAuthenticator` (secret-ветка) не имеют ни одного теста; все webhook-кейсы идут с `webhook_secret=null`. Атакующий, знающий allowlist IP, может прислать фейковый `payment.succeeded`. Как минимум: valid-signature accepted / tampered-body rejected / missing-signature rejected.
3. **[P0] Нет E2E checkout-сценария.** Ни одного сквозного прогона event→seat→cart→checkout→pay(webhook)→ticket(email) через HTTP-маршруты. Отдельные звенья покрыты, стыки — нет (именно на стыках в истории проекта случались 500 и 404).
4. **[P1] Feature-suite невоспроизводим вне CI.** Требование PHP≥8.4.1 + MySQL-only (без SQLite fallback) означает, что локальный дев и большинство reviewer-машин не могут запустить 67 файлов. Регрессии будут ловиться только на CI с задержкой и дорого.
5. **[P1] PHPStan/Pint объявлены, но не исполняются в CI.** Level-5 конфиг простаивает; форматирование не константно. Дёшево добавить в `verify`/отдельный job.
6. **[P1] Нет dependency-security gate.** `composer audit --format=json`, `npm audit` (сейчас намеренно выключен), Dependabot/Renovate, secret scanning — отсутствуют.
7. **[P2] SEO-маршруты без тестов** при задокументированной истории поломок (500 на `sitemap-venues`, 404 на unrouted Media). Минимум: 200+XML-validity+ссылки только на published events.
8. **[P2] RBAC ролей не покрыт.** Scope/key и tenant-isolation отличные, но «staff не может публиковать событие, owner не может удалять организацию» — непроверяемо.
9. **[P2] Идемпотентность проверена как политика, но не как middleware-гонка** (два одновременных запроса с одним Idempotency-Key; запись in-flight под реальной БД).
10. **[P3] Время не контролируется (`travelTo`)** — потенциальная ночная flakiness hold-expiry/reminder-тестов; низкая вероятность, но бесплатная страховка.
11. **[P3] Branch protection/settings не верифицируемы из кода** — убедиться, что 4 job обязательны при merge.

---

## 6. Рекомендуемый обязательный набор CI-проверок (required checks при PR)

Уже есть и должен остаться mandatory:
1. `Verifiers` (lint + kernel suite + 10 ratchet-верификаторов + composer validate)
2. `Schema` (применение DDL к MySQL 8.4 + счётчики + триггер)
3. `PHP test suite` (PHPUnit против MySQL 8.4)
4. `Frontend` (vue-tsc + vitest + vite build + артефакты dist)
5. `Container files` (compose config + hadolint + docker build)

Добавить:
6. **Static analysis**: `vendor/bin/phpstan analyse --no-progress --error-format=github` (ratchet baseline, список ошибок только сокращается)
7. **Formatting**: `vendor/bin/pint --test`
8. **Dependency audit**: `composer audit` + `npm audit --audit-level=high` (отдельно от основного npm ci) + Dependabot/Renovate config
9. **Concurrency regression suite** (новый): тесты oversell/idempotency-race, вынесенные в отдельный job с MySQL, чтобы их падение было однозначно видимым
10. **Smoke E2E** (новый): Playwright, один happy-path checkout + один webhook-driven paid flow против docker-compose стека
11. **Migration round-trip**: `migrate:fresh` → `migrate:rollback` (или сверка `doctrine/schema-dump` ≡ migrations.sql) — защита от необратимых миграций

Все 11 должны быть в branch protection as **required status checks**.

---

## 7. План создания тестов по приоритетам

### Фаза 1 (1–2 недели) — деньги и безопасность
1. `ConcurrentBookingTest` (Feature, MySQL): два подключения резервируют один seat одновременно → ровно один успех, CHECK/lock не даёт available уйти в минус; аналог для standing zone (count-based). *Доказывает P0-1.*
2. `WebhookSignatureTest`: positive (верный HMAC принят), negative (подменённый amount → 4xx, заказ не оплачен), absent signature → reject; replay того же подписанного payload не приводит к повторной оплате. *P0-2.*
3. `IdempotencyMiddlewareRaceTest`: два параллельных POST с одним ключом → один side-effect, второй replayed/conflict из таблицы `idempotency_keys`. *P1.*

### Фаза 2 (2–4 недели) — сквозные сценарии
4. `CheckoutFlowTest` (Feature, HTTP-уровень): POST event publish → GET availability → POST cart → POST checkout → POST webhook(signed) → GET ticket + Mail::assertSent. Один тест = вся воронка. *P0-3.*
5. `RefundFullCycleTest` (Feature): refund через сервис → provider fake → webhook refund.succeeded → order partially/fully refunded → склад возвращён (если политика return-to-stock) → письмо. Дополняет unit-PaymentSettlement.
6. Playwright smoke: форма покупки на SPA (хотя бы happy path) — опционально, если решаем держать browser-слой.

### Фаза 3 (в фоне, дешёвые победы)
7. SEO: `SitemapRoutesTest` — 200, content-type XML, только published events, canonical URL, robots.txt. 
8. RBAC: матрица role×action для 5 самых опасных операций (publish event, issue refund, delete session, manage keys, export PII) через API.
9. Перевести hold-expiry/reminder-тесты на `travelTo()/setTestNow()`.
10. Включить phpstan+pint+audits в CI (п.6–8 раздела выше).
11. Документировать/сделать devcontainer или compose-profile для локального запуска PHPUnit (PHP 8.4 + MySQL 3307) — снять P1-4.

---

## 8. Минимальный release-gate для production

Выпуск **недопустим**, если любой из пунктов красный:

1. **Kernel suite** (`php tests/run.php`) — 684 unit-инвариантов домена.
2. **PHPUnit Feature suite** против чистой MySQL 8.4 — включая `PaymentWritePathTest`, `OrderWritePathTest`, `CartWritePathTest`, `EmbedSecurityTest` (деньги, склад, tenant-isolation).
3. **Schema job** — DDL применяется, счётчики 64/684/1, триггер неизменяемости срабатывает в обе стороны.
4. **Все 12 статических верификаторов** (ratchets не растут).
5. **Frontend job** — typecheck + 233 vitest + успешный build с артефактами.
6. *(после Фазы 1)* **ConcurrentBookingTest** — ни одного oversell-сценария в красном статусе.
7. *(после Фазы 1)* **WebhookSignatureTest** — подпись обязательна и проверяема.
8. *(после Фазы 2)* **CheckoutFlowTest** — сквозная воронка жива.
9. **composer/npm audit** без critical-уязвимостей зависимостей (или с задокументированным acceptance).

Без пунктов 6–8 система формально может выпускать прод с зелёным CI, но с непроверенными oversell и подделкой платежей — это два сценария прямого финансового ущерба.

---

## 9. Итоговая оценка

| Аспект | Оценка | Обоснование |
|---|---|---|
| Глубина unit-покрытия домена | **A** | 684+233 теста, реально проходят; golden masters; ratchet-верификаторы связывают код со схемой |
| Интеграционное покрытие (БД/API) | **B** | Отличные write-path/tenant тесты, но неисполнимы вне CI, нет concurrency |
| Платежи / возвраты / идемпотентность | **B−** | Логика урегулирования образцовая; дыра — подпись webhook и middleware-гонка |
| E2E пользовательских сценариев | **D** | Полностью отсутствует |
| SEO / Installer / RBAC-роли | **D** | Тестов нет (при задокументированных поломках в прошлом) |
| CI/CD автоматизация | **B+** | 4 продуманных job на PR; минус phpstan/pint/audits, неизвестна requiredness |
| Детерминизм/flakiness | **B** | Хорошая изоляция сети/почты/порядка; минус контроль времени и окружения |
| **Способность автоматически обнаруживать регрессии** | **B−** | Выше среднего по рынку для MVP: статические ratchets ловят класс drift-регрессий, который обычно не ловит никто; но три финансовых P0-сценария (гонка мест, подделка webhook, стыки checkout) остаются слепыми зонами |

**Главная находка аудита:** проект уникально силён в «контрактной регрессии» (код↔схема↔OpenAPI↔машины состояний) и пропорционально слаб в «поведенческой конкурентной регрессии» — именно там, где биллинг-система теряет деньги. Инвестиции в раздел 7 Фаза 1 окупятся быстрее всего.

*Отчёт подготовлен без изменений тестов и приложения; все утверждения «зелёное/красное» основаны на фактических прогонах в разделе 2 либо на явной пометке «не исполнимо в данной среде».*
