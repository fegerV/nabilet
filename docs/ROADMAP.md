# NABILET Roadmap

> Актуальный production-аудит: [`PRODUCTION-READINESS.md`](PRODUCTION-READINESS.md) (срез 2026-10-27).
> Исторические заметки и первоначальный этап плана ниже — не источник текущих
> незакрытых задач; актуальные статусы собраны в разделах «Оставшиеся релизные
> задачи» и «Журнал исправлений» ниже.

## Дерево проекта (текущие компоненты)

```
C:\Project\nabilet
├─ app/Modules/            ← Laravel API и предметные модули
├─ resources/js/           ← Vue 3 + Vite + Pinia
│  ├─ lib/                 ← api.ts, inventory.ts, hall.ts, metrika.ts, tickets.ts
│  ├─ components/ui/       ← UI-кит
│  ├─ components/seat/     ← SeatMap, SeatLegend, OrderSummary
│  ├─ pages/storefront/    ← витрина и покупка
│  ├─ pages/admin/         ← админка, включая email-шаблоны и webhook-подписки
│  └─ router/              ← hash-режим
├─ dist/                   ← активный Vite build (outDir=dist)
└─ docs/
   ├─ ROADMAP.md
   ├─ PRODUCTION-READINESS.md
   └─ openapi.yaml
```

## Реализовано

- Публичный каталог и покупка работают через API; SEO-страницы по slug и JSON-LD.
- Холд, checkout, оплата YooKassa, выпуск билетов и check-in — сквозной контур.
- Hall editor: импорт схем, редактирование мест и цены, сведения о площадке.
- Админские редакторы транзакционных email-шаблонов и исходящих webhook-подписок.
- P0-код доставки почты и вебхуков реализован; шаблоны по статусам заказа;
  вебхуки подписаны HMAC-SHA256 и защищены от SSRF.
- Личный кабинет покупателя: профиль, пароль, заказы, сессии (`/me/*`).
- «Клуб зрителей», фаза 1 — избранное и подписки на письма о новых сеансах:
  схема (`018`), 6 путей `/me/*`, письма подписчикам, витрина «♡»/«🔔 Следить»,
  экраны `/favorites` и `/subscriptions`.
- «Клуб зрителей», фаза 2a — бонусный счёт: схема (`019`), кэшбэк 5 % за
  оплаченный заказ (`BonusPolicy`/`BonusService`, идемпотентность по
  `dedupe_key = accrual:{order_id}`, начисление из `OrderBonusObserver`),
  чтение через `GET /me/bonus` и `GET /me/bonus/transactions`, витрина
  (чип «✦ N» в шапке, плитка «Бонусный счёт» в кабинете, экран `/bonus`
  с историей движений и догрузкой). Списание бонусов в чекауте — ✅ реализовано (фаза 2b).
  План и статус — `LOYALTY-PLAN.md` (фазы 3–5: уровни, рефералка,
  розыгрыши — впереди).
- Удалены неиспользуемый `lib/mock.ts` и демо HTML/ассеты.
- **Auth reset/verify email** реализованы (`AccountTokenService`, `AccountRecoveryService`),
  пути приведены к контракту; placeholder-разделы админки удалены.
- **CI**: 6 job'ов — верификаторы, PHPStan level 5, PHPUnit на MySQL 8.4, фронт
  (`vue-tsc` + Vitest + `vite build`), схема спеки, Docker-образ.
- **Возвраты**: решение о сумме — только `RefundLedger::evaluate()`.
- **Деньги — единый владелец `Money`**: конверсия minor↔decimal в Payments, формат суммы
  заказа в письме, символ валюты, цена билета в письме/PDF, цена «от N ₽» на посадочной.
  Ручных `number_format` для денег вне `Money` не осталось.

## Оставшиеся релизные задачи

Сверяться с `PRODUCTION-READINESS.md` (срез 2026-10-27). Главное перед production:

1. **Среда:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`; SMTP + `MAIL_FROM_ADDRESS`;
   воркер `queue:work` (supervisor) + минутный cron `schedule:run`; `CACHE_STORE`/`SESSION_DRIVER`
   → `redis`/`database`; права на `storage/` и `bootstrap/cache`; HTTPS.
2. **Живой e2e YooKassa** с боевыми ключами: платёж → вебхук → билет → возврат.
3. **Спека/пути:** решить 29 спек-путей (`/admin/*` → ресурсные пути, `/webhooks/yookassa`
   → `/payments/webhooks/{provider}`, `/embed/*`) — двигать роуты или править `openapi.yaml`;
   привести спеку к 3 триггерам жизненного цикла.
4. **Не построено:** translations-подпути, Embed-виджет. Соц-вход (Яндекс/VK/Telegram)
   построен и выключен без кредов провайдеров — включение только через `.env`.
5. **Эксплуатация:** бэкапы БД, мониторинг/алерты, ротация секретов, PII-free логи, 152-ФЗ.
6. **Android Checker** — отдельный проект (офлайн-бандлы), вне web-MVP.

## Журнал исправлений — кампания «правило скопировано, а не вызвано» (2026-10)

Каждый коммит устраняет один класс дефекта: правило имеет одного владельца, но было
скопировано в N местах и молча расходилось. Формат: `SHA — суть`.

- `9f3d5530` — возврат: сумма решается `RefundLedger`, а не копией в `RefundService`.
- `91bf7eb0` — платежи: конверсия minor↔decimal через `Money`, не float-копия в `YooKassaProvider`.
- `e13ea1ae` — письмо: сумма заказа через `Money::format('ru_RU')`, не ручной `number_format`.
- `021b1e9b` — символ валюты через `Money::symbolFor()` (убрана копия в PDF билета).
- `b36a8f54` — цена билета: `Money::format(omitWholeKopecks)` для письма и PDF; источник
  эмитит `Money::of()`, а не float. Сторож — `TicketPriceFormatSingleOwnerTest`.
- (Batch 47) — цена «от N ₽» на посадочной: `Money::format(omitWholeKopecks)`, убрана
  своя ветка целых рублей. Сторож — `LandingPriceFormatSingleOwnerTest`.
- (Соц-вход, ТЗ §17) — построен вход через Яндекс, VK и Telegram: `social_accounts`
  (app-only), `SocialProvider`/`SocialIdentity`/`TelegramInitData`/`OAuthState`,
  `SocialAuthService` (единое правило «к какому аккаунту привязать»),
  `GET /auth/{provider}/start|callback`, `POST /api/v1/telegram/auth`, экран
  `#/auth/callback` и кнопки на форме входа. Две копии правил поймали сторожа и
  устранены по ходу: HMAC для `initData` ушёл в `HmacSignature::deriveKey()`
  (`HmacSignatureCopyTest`), `users.status` — в `UserStatus::ACTIVE`
  (`PublicationStatusCopyTest`). Найдено и исправлено попутно: докблок
  `TicketGeneratorService::generateTicketData()` всё ещё объявлял `price: float`
  после Batch 46 — PHPStan ловил это как unmatched-исключение в baseline.

Все батчи: гейты 14/14, unit и PHPUnit зелёные, статический сторож делает возврат копии
невозможным без падения теста.

## История

Ниже оставлен первоначальный план миграции админки и реализации этапов. Отмеченные
в нём старые пункты не считать актуальными задачами, если их статус не отражён
в разделе «Оставшиеся релизные задачи» выше.

---

