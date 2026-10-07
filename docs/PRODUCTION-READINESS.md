# NABILET — готовность к продакшену (актуально 2026-10-07)

> **Источник истины о текущем состоянии.** Исторический срез «что осталось до запуска» —
> [`LAUNCH-READINESS.md`](LAUNCH-READINESS.md) (срез 2026-09-20, прикладной слой тогда не был
> написан). Сейчас прикладной слой реализован; этот файл фиксирует **реальное** состояние,
> конкретные незавершённые места, моки/заглушки и план закрытия. Обновляется по каждому
> крупному этапу. Сверяться перед релизом.

---

## 1. Краткий вывод

**Готово и работает:** прикладной слой (22 контроллера, 18 API Resources), Vue 3 SPA
(витрина + админка + конструктор залов + конструктор билетов), платёжный провайдер YooKassa
(реальные вызовы `api.yookassa.ru/v3`, проверка подписи вебхука, возвраты), доменная логика
ядра (покрыта тестами). **Проверки зелёные: Vitest 128/128, PHPUnit 105/105 (430 assertions),
vue-tsc и Vite production build** (проверено 2026-10-07).

**P0 в коде закрыт 2026-10-07:** подключена транзакционная почта по редактируемым шаблонам,
исходящие вебхуки по переходам статусов заказа, удалены `mock.ts` и демо-страницы (включая
сгенерированные копии/ассеты из `public_html/build`). Реализованы статусы `awaiting_payment`,
`paid`, `cancelled`, `refunded`, `partially_refunded`, `payment_failed`, `expired` (все допустимые статусы по `ck_orders_status`, кроме исходного `pending`).

**Релиз всё ещё требует настройки среды:** включить очередь `database`, применить миграцию
таблиц `jobs`/`failed_jobs`, включить планировщик раз в минуту (он разбирает очередь и повторы
вебхуков), заполнить реальные SMTP-параметры и `MAIL_FROM_ADDRESS`. В текущем локальном
`.env` SMTP-хост пустой, поэтому почта не может уйти до настройки; SMTP и живой endpoint
партнёра не тестировались. P0-код закрыт, конфигурация production-транспорта — ещё нет.

**Остаются перед MVP:** восстановление пароля и верификация email (`AuthController` — 3
эндпоинта `notImplemented`); довести CI до прогона PHPUnit + Vitest + сборки образа;
placeholder админ-секций `AdminPlaceholderPage.vue` (только для разделов, не заменённых
реальными страницами).

---

## 2. Что реализовано (проверено по дереву и grep, 2026-10-07)

| Область | Статус | Как проверено |
|---|---|---|
| HTTP-слой | Модульные контроллеры и API Resources, маршруты централизованы в `routes/api.php` | `app/Modules/**/Http/Controllers/`, `*/Http/Resources/` |
| Аутентификация/роли | Sanctum + `EnsureAdminRole`, `AuthResource` отдаёт роли, write-роуты защищены | `AuthController`, middleware |
| Каталог/витрина | Catalog, EventPage, SeatSelection, Checkout — на реальном API (`lib/api.ts`) | `resources/js/pages/storefront/` |
| Админка (Vue) | Логин, Мероприятия, Сеансы, Площадки, Залы, Заказы, интеграции, редактор шаблонов писем, управление исходящими вебхуками | `resources/js/pages/admin/` |
| Конструктор залов | Загрузка/автосейв/публикация схем, импорт JSON, конвертация в инвентарь | `HallEditorPage.vue`, `HallSchemaVersion::toInventoryFormat` |
| Платежи | `YooKassaProvider` (реальный API), `PaymentProviderRegistry`, `WebhookSignatureVerifier`, `RefundService` | `app/Modules/Payments/` |
| Транзакционная почта | `TransactionalMailService`, редактируемые `notification_templates`, статусы заказов, `Notification`-лог + `afterCommit()` queue | `app/Modules/Notifications/`, `OrderObserver` |
| Исходящие вебхуки | Подписки по организации и событиям, HMAC-SHA256, SSRF guard, retry policy, журнал доставок | `app/Modules/Webhooks/`, `webhooks`/`webhook_deliveries` |
| Инвентарь/корзина/заказы/билеты | Сквозной контур реализован; цены, холды, чекаут, выпуск билетов | `Cart`/`Inventory`/`Orders`/`Tickets` модули + PHPUnit |
| Тесты ядра (dependency-free) | 596 методов / 1216 утверждений (исторически) | `tests/run.php` |
| Тесты прикладного слоя | Vitest 128/128, PHPUnit 105/105 (430 assertions) | запуск 2026-10-07 |
| Документация | ARCHITECTURE, ROADMAP, deploy-timeweb, CODE-QUALITY-GUIDE, Техническая спецификация | `docs/` |

---

## 3. Не реализовано / моки / заглушки — конкретно

| # | Где | Что | Серьёзность | Влияние на прод |
|---|---|---|---|---|
| 1 | `app/Modules/Auth/**` | `forgot-password` / `reset-password` / `verify-email` — **реализованы** (см. ниже) | ✅ закрыто | Восстановление пароля и подтверждение адреса работают; пути приведены к контракту (`/auth/password/forgot`, `/auth/password/reset`, `/auth/email/verify`) |
| 2 | `resources/js/pages/admin/AdminPlaceholderPage.vue` + `router/index.ts` catch-all | Placeholder остаётся только для админ-разделов, у которых ещё нет реальной страницы | 🟡 средняя | Такие разделы нельзя считать реализованными; письма и вебхуки к ним больше не относятся |
| 3 | `.env` → `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Локальная конфигурация не содержит SMTP-хоста/учётных данных | 🔴 высокая до настройки | Письмо корректно создаётся и ставится в очередь, но транспорт не сможет подключиться; реальные credentials не коммитить |
| 4 | `app/Modules/Embed/**` | Embed-виджет — скелет (`EmbedDomain` модель + `EmbedServiceProvider`); контроллер/роут/рендер не подтверждены | 🟡 средняя | ТЗ §: виджет для чужих сайтов; функциональность не доведена |
| 5 | Android Checker | Отдельное приложение (офлайн-бандлы) | ⚪ вне web-MVP | Не блокирует веб-продажи |

**Примечание по безопасности/качеству:** ранее отмеченные в `LAUNCH-READINESS.md` дефекты
(перекос пространств имён, 88 выдуманных колонок, отсутствие `vendor/`/`composer.lock`,
снятые защиты `.gitignore`) — **закрыты** в слияниях 2026-09-21. Текущий `composer.lock`
отслеживается, пространства имён приведены к `Nabilet\Modules\`, секреты в `.gitignore`
защищены.

---

## 4. Готовность по областям

| Область | Оценка | Комментарий |
|---|---|---|
| Ядро / доменная логика (прайсинг, корзина, инвентарь, заказы, билеты, чекин, платежи, приватность, тенантность) | 🟢 | Покрыта тестами, дефекты доказаны исполнением |
| API / HTTP-слой | 🟢 | Валидация, роли, мультитенантные админские маршруты и централизованное подключение |
| Фронтенд (витрина + админка + редактор залов + конструктор билетов) | 🟢 | Vue 3 + TS, на реальном API, `vue-tsc` и production build проходят |
| Платежи (YooKassa: создание/статус/возврат + входящий вебхук) | 🟡 | Реализовано; **не проверено** против живого API (нужны реальные ключи и e2e) |
| Уведомления / почта | 🟡 | Письма и вебхуки подключены и протестированы; production SMTP/cron не настроены |
| Auth (reset/verify email) | 🟡 | Логин/роли есть; восстановление пароля и верификация — заглушки |
| Инфраструктура (Docker / деплой / бэкапы / мониторинг / ротация секретов / 152-ФЗ / PII-логи) | 🟡 | Docker-файлы и `docs/deploy-timeweb.md` есть; образ не собирался; бэкапы/мониторинг/ротация/логирование без PII не проверены |
| CI | 🟡 | `.github/workflows/ci.yml` гонит dependency-free верификаторы ядра; **не запускает PHPUnit и Vitest**, не собирает образ |

---

## 5. План закрытия перед продакшеном

### P0 — код закрыт; остаётся настроить production
- [x] Транзакционная почта по `awaiting_payment`, `paid`, `cancelled`, `refunded`,
  `partially_refunded`, `payment_failed`, `expired`; шаблоны редактируются
  через админку, письмо оплаты включает выпущенные билеты.
- [x] Исходящие вебхуки по тем же статусам: HMAC-SHA256, ограничение SSRF, 4xx не
  ретраятся, 3xx не переходятся, backoff+jitter и идемпотентность fan-out.
- [x] Удалены `mock.ts`, демо-страницы/копии из `public_html/build` и мёртвая точка входа.
- [x] Удалены четыре устаревших дубля моделей `app/Modules/Notifications/Models/*`
  (`Consent`, `Notification`, `NotificationTemplate`, `PrivacyRequest`) — они объявляли
  колонки, которых нет в схеме (`subject_template`, `is_subscribed`,
  `notification_template_id`, `reason`), и `User` ссылался именно на них, то есть
  relations `consents()/notifications()/privacyRequests()` упали бы в рантайме.
  `User` переведён на канонические `App\Models\*`; `PrivacyRequest` создан в
  `App\Models` по реальной схеме. `verify-models-schema`: 68→64 модели,
  460→442 поля, долг `INVENTED_FIELDS` 69→59.
- [x] Исправлен `SendWebhookDeliveryJob`: `error_message` обнулялся и при `abandoned`
  (4xx/исчерпание лимита), поэтому причина остановки доставки терялась — ровно то,
  ради чего `DeliveryOutcome` разделяет `permanent_failure` и `retry_limit_reached`.
  Теперь сообщение сохраняется; `error_message` очищается только при успешной доставке.
- [ ] На production настроить SMTP, применить миграцию `jobs`/`failed_jobs`, установить
  минутный cron для `schedule:run` и выполнить живой smoke test транспорта.

### P1 — качество MVP
1. **Auth:** `forgot-password` / `reset-password` / `verify-email`. — **✅ сделано.**
   `AccountTokenService` выпускает подписанный stateless-токен (Crypt, полезная
   нагрузка: user public_id + e-mail + purpose + срок + отпечаток хеша пароля);
   `AccountRecoveryService` проводит сброс (в транзакции, с отзывом всех сессий)
   и подтверждение адреса; письма идут по редактируемым шаблонам
   (`account.password_reset`, `account.email_verification`). Пути эндпоинтов
   приведены к `docs/openapi.yaml`. Лимит `throttle:auth` — 5/мин на пару
   e-mail+IP и 20/мин на IP.
2. **Placeholder-разделы:** заменить catch-all `AdminPlaceholderPage.vue` реальными
   экранами или явно пометить оставшиеся экспериментальными.
3. **CI:** добавить шаги `php vendor/bin/phpunit` + `vitest run` + `docker build` (после
   появления воспроизводимого `composer install`).

### P2 — полнота
6. **Embed-виджет:** довести до рабочего состояния или явно пометить experimental/out-of-scope.
7. **Инфраструктура:** бэкапы БД, мониторинг/алерты, ротация секретов, логирование без PII,
   соответствие 152-ФЗ (`consents`, `privacy_requests`).
8. **Android Checker** — отдельный проект (офлайн-бандлы, синхронизация).

---

## 6. Что проверено 2026-10-07 (методика)

- `node node_modules/vitest/vitest.mjs run` → **128/128** (managed Node v22.22.2, системный v24.12.0,
  пулы `forks`/`threads`, после `rm -rf node_modules/.vite`). Ранее фиксировавшаяся ошибка
  `Cannot read properties of undefined (reading 'config')` **не воспроизводится** — была транзиентным
  состоянием кэша, не дефектом кода.
- `php vendor/bin/phpunit --no-coverage` → **105/105** (430 assertions), включая новые тесты
  транзакционных писем, OrderObserver, HMAC/webhook delivery, retry policy и SSRF guard;
  `RefreshDatabase` делает `migrate:fresh` на
  `nabilet_test_suite` без ошибки 1419 (триггерная миграция `harden_hall_schema_lifecycle` проходит —
  `log_bin_trust_function_creators=1` выставлен глобально).
- `vue-tsc --noEmit` и `vite build --config vite.config.ts` → прошли; `verify-migrations.php` → 16/16;
  `verify-models-schema`, `verify-purity`, `verify-module-structure`, `verify-autoload`,
  `verify-error-envelope`, OpenAPI и contract-schema — прошли.
- Оставшиеся места проверять по `notImplemented` (Auth), placeholder-роутам, production SMTP/cron
  и live e2e платежей/вебхуков.

### Живой сквозной прогон P0 (2026-10-07, рабочая БД)

Проведён на реальном сервере (`artisan serve`) и рабочей БД, не только в тестах:

- Вход админом → `GET /api/v1/notification-templates` отдаёт 7 шаблонов и словарь
  переменных; без токена — **401**.
- `POST /notification-templates/2/preview` рендерит HTML и text письма `order.paid`
  с подстановкой всех переменных (имя, номер заказа, мероприятие, дата, площадка,
  форматированная сумма, блок билетов).
- Подписка на вебхук с публичным `https` создаётся (**201**, секрет + `retry_limit=10`);
  внутренний адрес `http://127.0.0.1:...` отвергается (**422**) — SSRF-guard работает.
- Переход заказа `pending → paid` ставит **2 джобы** (`SendOrderNotificationJob` +
  `DispatchOrderWebhookJob`); воркер доводит их до конца, fan-out создаёт
  `webhook_deliveries`, `SendWebhookDeliveryJob` делает реальный подписанный POST.
- Ретрай-петля: `webhooks:retry-pending` по «состаренному» `next_retry_at` поднимает
  повторно ровно 1 доставку.

Замечания окружения (не дефекты кода): миграция `2026_10_07_000500_create_queue_tables`
в рабочей БД была **Pending** и применена в ходе проверки — без неё любая `dispatch()`
падала бы на отсутствии `jobs`. Локальный `MAIL_MAILER=smtp` без SMTP-сервера и
отсутствие CA-бандла дают `failed` в `notifications` и TLS-ошибку у вебхука —
на production с настроенным SMTP и валидным сертификатом это ожидаемо исчезает.
