# NABILET — реестр владельцев правил

Один владелец — одно правило. Здесь полный список; `MEMORY.md` держит только шапку и указатель.
Читать ПЕРЕД правкой любого модуля: почти каждый пункт — это «правило скопировано, а не вызвано»,
которое уже один раз чинили. Формат: правило → кто владелец → кто сторож (тест/гейт).

## Инварианты: владельцы (один владелец — одно правило)
- `organization_id` из токена, не тела. Статус события — только `POST /events/{id}/publish|cancel`. `/users/search` выше `/users/{publicId}`; `Core/Users/*` под `['auth:api','admin']`.
- `OrganizationContext` — singleton через `withSingletons()` в `bootstrap/app.php` (провайдер `NabiletServiceProvider` НЕ регистрируется: бутит все модули). Резолвинг: guard `api` по bearer-токену (глобальный middleware ДО `auth:api` → `$request->user()` видит только `web`), затем членство в `user_organization`. Публичный `POST /promo-codes/validate` берёт организацию из КОРЗИНЫ; без корзины — 422, не 500.
- Организация запроса как int — только `OrganizationContext::idOrNull()` (null = «список пуст»); fail-closed — `id()` (`ctype_digit` из-за `withoutScope()`). Сторож — `TenantIdConversionCopyTest`.
- `AssetUrl::storage()`→`/storage/...`, `::public()`→от корня — единственный владелец корней.
- Сессия покупателя: наличие токена ≠ живая сессия. Под `requiresCustomer` guard зовёт `ensureCustomerSession()` (`GET /me` с `as:'customer'`, кэш на токен) → 401 = `login?redirect=…&reason=expired`. Сеть/5xx не разлогинивают.
- Корзина: сумма активных `seat_holds.quantity` == `cart_items.quantity`; grace — только `HoldGrace`. `order_items` без `session_id`: `inventory_item_id → inventory_items.session_id`.
- Purity: `app/Core/Support` и `*/Domain/` работают без `vendor/`; прямой `config()`/`app()` — красный.
- `app/Core/Support`: `Money` (`format($locale,$omitWholeKopecks)`, `symbolFor()`), `CssColor` (`normalizeHex` НЕ разворачивает `#abc` — это `expand()`), `DatabaseId` (порог 19 цифр), `LikePattern`, `Paging` (`limit($requested,$default,$max)` = `max(1, min($max,…))`), `PackedIp`, `QrSigner`, `RuText`, `StaffRole`, `PublicationStatus`, `Sha256Hex`, `HmacSignature`, `SignedPayload`, `Text` (`stripControlChars($text,$unicode)` — единственный владелец класса управляющих символов C0+DEL; флаг `u` — параметр, не копия регулярки).
- **Дата для человека на сервере** — только `Core\Support\RuText`, ДВА формата, оба намеренные:
  `date()`/`dateTime()` → «25 декабря» / «25 декабря, 19:00» (посадочные страницы, `LandingPageRenderer`);
  `stamp()`/`stampDate()`/`clock()` → «20.11.2026 19:30» / «20.11.2026» / «19:30» (письма, PDF-билет,
  `event_date`, «Дата и время:» на странице события). Все три принимают `\DateTimeInterface` (модельный
  `Carbon`), строку из сырого `DB::table()` и `null`; пояс источника СОХРАНЯЕТСЯ (`createFromInterface`).
  Было 10 литералов `format('d.m.Y H:i')`/`format('d.m.Y')`/`format('d.m.Y, H:i')`/`format('H:i')` в 8 файлах.
  Формат `event_date` закреплён `InventoryRepositoryContractTest` («20.11.2026 19:30») — менять только
  через владельца. Сторож — `tests/Unit/RuTextTest.php` (+ статический запрет второго `format('d.m.Y`/`format('H:i')` в `app/`).
  НЕ путать: `SessionIssuer::STAMP_FORMAT` (`Y-m-d H:i:s.u`) и касты `datetime:Y-m-d H:i:s.u` — формат
  СЕРИАЛИЗАЦИИ в БД; `\DATE_ATOM`/`'c'` — ISO для API; `AnalyticsReportService` `Y-m-d H:i:s` — граница запроса.
- «Это сотрудник?» — только `StaffRole::isStaff()` + `ROLES`. Сторож — `StaffRoleCopyTest`.
- **Публикационный статус** — только `Core\Support\PublicationStatus` (Events/Organizations/Venues/halls/artists). `all()`/`validationRule()` — базовый словарь; у `organizations.status` шире (`suspended`) → туда НЕЛЬЗЯ. «Видно ли» (`publiclyVisible()`) один. Сторож — `PublicationStatusCopyTest`. Свои словари: `seats` (active|blocked|disabled), `checkin_devices`, `carts`.
- **Журнал `payment_transactions`** — `Payments\Domain\TransactionType`: словарь `type` + «тип → допустимые статусы» (`PaymentStateMachine`). Единственный писатель `PaymentRepository::addTransaction()` зовёт `assertAllowed()`; прямой `->transactions()->create()` запрещён сторожем. Колонка — НЕ «словарь провайдера»: значения пишем мы, payload → `payload_json`.
- **У таблицы один владелец-модель.** `webhooks` → `Webhooks\Models\Webhook` (cast `events_json => array`); `webhook_deliveries` → `Webhooks\Models\WebhookDelivery`; `webhook_events` модели НЕТ — `insertOrIgnore` через `DB::table`. Сторож — `WebhookModelOwnershipCopyTest`.
- **`INVENTED_FIELDS` в `verify-models-schema.php` проверяется на устаревание**: переставшее срабатывать исключение роняет гейт (`ApiKey`, `Page`, `Redirect`).
- `public_id` генерирует ТОЛЬКО трейт `HasPublicId`. Сторож — `PublicIdGenerationCopyTest` (читает СХЕМУ, связывает через `protected $table`). Исключение: `TicketService` (нужен QR ДО `create()`).
- Отпечаток токена — только `Sha256Hex::of()` (`ALGORITHM`+`LENGTH` вместе: `CHAR(64)` задан алгоритмом). Сторож — `Sha256HexCopyTest`.
- Подпись/хеш с секретом — только `HmacSignature`; байты — только `SignedPayload::encode()`. Пустой секрет — исключение. Форматы QR `QrSigner` (NB1) и `TicketService` РАЗНЫЕ — не унифицировать.
- `Content-Type` — только `Core\Http\ContentType`; HTML — `HtmlResponse::of()`. JSON и PDF без charset; текстовые объявляют `charset=utf-8` ЯВНО. Сторож — `ContentTypeVocabularyTest`.
- «Число в пути — id, остальное — public_id»: `Core\Database\IdentifierLookup::first()` (нужен `Model`; без `@template TModel of Model` phpstan теряет тип). Сторож — `IdentifierLookupCopyTest`.
- HTML-вставки SEO — только `Core`: `JsonLd::escape()/script()` (только `<`/`>`, `htmlspecialchars` НЕЛЬЗЯ), `MetaTag::named()/property()`, `DataLayer::push()` (`JSON_HEX_TAG`), `SearchVerification`. Сторож — `SeoEmbeddingCopyTest`.
- Письмо по шаблону — только `NotificationTemplateRenderer` (`find()`: code+channel+locale+active); `TransactionalMailService`/`AccountMailService` делегируют (списки переменных РАЗДЕЛЬНЫЕ). Сторож — `NotificationTemplateRenderCopyTest`. `MAIL_MAILER=log` + `LOG_LEVEL=warning` = письмо в лог НЕ попадает (лог-мейлер пишет `debug`).
- Шаблон билета — только `TicketTemplateDefaults` (400×600): `events.ticket_template_id` → активный макет организации → `defaultConfig()`. Копия — `POST /ticket-templates/{id}/duplicate`.
- Цена билета — только `Money::format()` (письмо `format('ru_RU')`, PDF `format('ru_RU', omitWholeKopecks: true)`, лендинг `'от ' . …`); источник эмитит `Money::of()`, не float. Сторож — `TicketPriceFormatSingleOwnerTest`, `LandingPriceFormatSingleOwnerTest`.
- **Рубли ↔ копейки на ФРОНТЕНДЕ** — только `resources/js/lib/money.ts`: `MINOR_PER_RUBLE`,
  `minorToRub()` (ТОЧНО, без округления — округляет показывающий), `rubToMinor(value, {allowZero})`
  (понимает запятую; ноль отвергает, кроме `allowZero`). `format.ts::money()` зовёт `minorToRub`,
  а не делит сам. Две политики нуля — параметр, не вторая копия: сетка зала `price < 1` отвергается
  сервером, цена ряда сеанса ноль РАЗРЕШАЕТ (бесплатный ряд).
  Единица измерения — В ИМЕНИ переменной: `groupSeatPriceRub` (рубли) → `groupSeatPriceMinor`
  (копейки). Дефект был ровно из-за имени: шаблон поделил на 100 уже рублёвое значение, и место
  за 1500 ₽ показывалось как 15 ₽.
  Единственное место без округления — `TicketCanvas` (билет = документ, «1 550,5» честнее «1 551»).
  Сторож — `tests/Unit/money.test.ts` (конверсия, round-trip, `allowZero`, запятая) + статический
  запрет `* 100`/`/ 100` рядом с деньгами вне `lib/money.ts` (ratchet: сейчас исключён только
  `lib/loyalty.ts` — там делят ПРОЦЕНТ).
  НЕ путать: `zoom / 100`, `opacity * 100`, `(a / b) * 100` (проценты в аналитике) — не деньги.
- **Русское склонение на ФРОНТЕНДЕ** — только `resources/js/lib/format.ts::plural(n, one, few, many)`
  (правило «11–14 склоняются как 5»). На сервере — его двойник `Core\Support\RuText::plural()`
  (письма/PDF); сверка автоматической быть не может, поэтому границы продублированы число-в-число в
  `tests/Unit/format.test.ts` (`RuTextTest.php` — эталон). Ярлыки `seatsLabel`/`ticketsLabel`/`rowsLabel`
  — единственный способ собрать «N мест / билетов / рядов»; `seatsLabel(0)` = «0 мест».
  Бинарная копия `n === 1 ? 'X' : 'Y'` верна для 1 и 5 и ЛОМАЕТСЯ на 2/3/4 — именно так в обзоре
  админки три фразы врали («2 мероприятий ещё в черновиках»). Фразы «Требует внимания» вынесены в
  чистый модуль `lib/attention.ts` (`attentionTasks(counts)`): страница считает числа, модуль строит
  текст. Сторож — `tests/Unit/format.test.ts` (таблица + статический запрет склейки числа с
  существительным и тернарника «1 → слово А, иначе Б» вне `lib/format.ts`) и `tests/Unit/attention.test.ts`
  (спрашивает ровно 2/3/4/5/11/21, на которых копия врала).
- **Форматирование дат для человека (фронтенд)** — только `resources/js/lib/format.ts`:
  `formatDate` («9 октября 2026»), `formatDateTimeLong` («9 октября 2026, 19:30»),
  `formatMonthYear` («октябрь 2026», именительный падеж — для автономной подписи периода),
  `formatDateTimeShort` («09.10.2026, 19:30»). Месяцы — из массивов `MONTHS_GEN`/`MONTHS_NOM`
  (русские склонения, прописаны в коде, НЕ `toLocaleString`/`Intl.DateTimeFormat`), поэтому вывод
  детерминирован и не зависит от локали браузера: на managed-хостинге без установленной ru-RU
  `toLocaleString('ru-RU', …)` мог бы отдать `Oct 9, 2026`. Раньше те же четыре формата жили
  РОЗНО — `mediaLibrary.ts` и `AdminRafflesPage.vue` определяли каждый свой `formatDate` (разные
  форматы под одним именем), а `AdminEventFormPage`/`AdminDashboard` встраивали `toLocaleString` /
  `Intl.DateTimeFormat` прямо в `computed`. Копии не лгали (для ru-RU форматы стабильны), но
  расходились бы при смене локали или правке одного места. `dateLong`/`dateFull`/`dateTime`/`time`
  — отдельные хелперы того же файла (месяцы из тех же массивов); не путать `formatDate` («9 октября
  2026», пустая → '') с `dateLong` («9 октября», без года) и `dateTime` («25 декабря, 19:00»).
  Сторож — `tests/Unit/format.test.ts` (таблица из 7 входов + статический запрет
  `toLocaleDateString` / `Intl.DateTimeFormat` / `function formatDate` вне `lib/format.ts`:
  вернуть копию — упадёт по имени файла).
- **Размер файла для человека (фронтенд)** — только `resources/js/lib/format.ts::humanSize()`
  («0 Б» / «N Б» / «N КБ» / «N.N МБ»). Раньше то же правило жило РОЗНО: локальный
  `humanSize` в `mediaLibrary.ts` (его домен — файлы) плюс собственный `formatSize`
  в `AdminLogsPage.vue` (тот же исход, но переписан иначе: `Math.round(bytes/1024)`
  против `(bytes/1024).toFixed(0)`) и встроенная математика `file.size / 1024 / 1024`
  прямо в строку ошибки и подпись превью `AdminEventFormPage.vue`. Копии были
  эквивалентны, пока их не тронут — правка одной разошлась бы с остальными.
  `mediaLibrary.ts` ре-экспортирует `humanSize`, поэтому старые импортёры
  (`eventGallery.ts`, `MediaPicker`, `AdminMediaPage`) не меняются.
  Сторож — `tests/Unit/format.test.ts` (таблица + статический запрет второго
  `formatSize`/`humanSize` и ручной математики `bytes / 1024 / 1024` /
  `bytes / (1024 * 1024)` вне `lib/format.ts` и `lib/mediaLibrary.ts`:
  временный `formatSize` в зонде ронял тест — не vacuous).
- **Применение бонусов в чекауте (фронтенд)** — только `resources/js/lib/loyalty.ts::applyBonuses()`.
  Раньше пять величин (лимит `capMinor`, применённая сумма `appliedMinor`, итог `totalMinor`,
  лимит в бонусах `capUnits`, остаток `leftUnits`) считались ДВАЖДЫ — в `usePurchaseFlow`
  (предпросмотр на витрине) и `CheckoutPage` (там же и отправка `bonus_amount`); формулы были
  побайтово равны, расхождения не было, но копия несла в себе беду: правка лимита в одном месте
  оставила бы вторую страницу со старым итогом, а сервер вернул бы 422 на новый. Владелец вызывает
  `spendCapMinor()` один раз на всю программу; обе страницы только передают корзину и флаг
  `useBonuses` и читают пять тонких обёрток (`bonusCapMinor`/`bonusMinor`/`totalMinor`/`bonusCapUnits`/
  `bonusLeftUnits`). `canUseBonuses` — UI-ворота показа блока (счёт загружен, на нём что-то есть,
  лимит > 0), НЕ величина из `applyBonuses`.
  Сторож — `tests/Unit/Storefront/bonusApplication.test.ts` (таблица из семи наборов входов + статический
  запрет живого `spendCapMinor(` и ссылки на `MINOR_UNITS_PER_BONUS` в обеих страницах: верни страницу
  к копии — упадёт по имени файла).
- **Валидация телефона покупателя (фронтенд)** — только `resources/js/lib/phone.ts::phoneError()`.
  Возвращает `null`, если в строке ровно 11 цифр (небуквенные символы отбрасываются до подсчёта),
  иначе `'Телефон — 11 цифр, начиная с 7'`. Раньше то же условие `phone.replace(/\D/g,'').length !== 11`
  было ВПИСАНО двумя буквально одинаковыми строками в `CheckoutPage.vue` и `usePurchaseFlow.ts`
  (в обоих — в `localErrors` computed, пишет `out.phone`). Копии компилировались, но разошлись бы при
  правке сообщения или условия. Теперь обе точки зовут `phoneError(phone.value)` и присваивают
  результат `out.phone`. Поведение сохранено побайтово: проверяется ТОЛЬКО длина (11), а «начинается
  с 7» — пока только текст в сообщении, не условие (если станет условием — менять в ОДНОМ месте).
  Сторож — `tests/Unit/phone.test.ts` (поведенческие тесты + статический запрет сырого
  `replace(/\D/g,'').length !== 11` и текста ошибки вне `lib/phone.ts`: временная копия в зонде
  роняла тест — не vacuous).
- **Очистка управляющих символов (текст, бэкенд)** — только `Nabilet\Core\Support\Text::stripControlChars()`.
  Единственный владелец класса C0+DEL `/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/` (константа `Text::CONTROL_CHARS`).
  Раньше тот же литерал был ВПИСАН двумя копиями — в `StorefrontService::text` (с флагом `u`) и
  `MatrixMessage::sanitize` (без `u`, намеренно: байтовый класс безопасен для невалидной UTF-8 из БД).
  Копии компилировались, но разошлись по флагу `u`; правка класса в одном месте разошлась бы со вторым.
  Теперь флаг `u` — параметр `stripControlChars($text, $unicode)`, расхождение видно в одном месте.
  Поведение сохранено побайтово: `preg_replace` с `?? ''` (Storefront) и `?? $body` (Matrix) эквивалентны —
  байтовый класс без `u` не возвращает null на практике, а с `u` на невалидной UTF-8 давал '' и раньше.
  Сторож — `tests/Unit/ControlCharsSingleOwnerTest.php` (поведенческие тесты + статический запрет литерала
  класса вне `app/Core/Support/Text.php` по всему `app/`: временная копия в `__control_probe.php` роняла
  тест — не vacuous).
- Payload мероприятия — только трейт `PreparesEventPayload`. Сторож — `EventPayloadOrderCopyTest`.
- Логи: `#/admin/logs` → `GET /admin/system/logs` → `LogReader` (запись = единица). Разбор — только `/\r\n|\n|\r/`, НЕ `\R` (без `/u` режет 0x85 внутри кириллицы). Секреты — `RedactSensitiveData::scrubJsonText()`.
- **Отзыв устройства контроля НЕОБРАТИМ.** `revoke()` меняет только `status` (`device_token_hash` остаётся — на строку ссылается `ticket_scans`). `PATCH /admin/checkin/devices/{device}` принимает ТОЛЬКО `revoked`; оживить — лишь новая выдача токена. `disabled` в CHECK есть, но не пишется — обязан распознаваться (отказ, не 500). В `openapi.yaml` правится только ТЕЛО; схема модели `[active, disabled, revoked]`.
- `verify-status-literals.php`: 16 словарей-владельцев; сверка по ПОЛНОМУ имени колонки (`orders.status` ≠ `orders.payment_status`). «Not enforced» — значения в >1 колонке (`active` не охраняется — 8 колонок).
- Два класса `Organization`: канон `Modules\Core\Organizations\Models\Organization`, legacy `Modules\Organizations\Models\Organization` (мёртв, 0 импортеров).
- `app/Models` вычищен: 12 моделей (8 настоящих + 3 шима под `InventoryRepositoryContractTest`); `verify-models-schema.php` сканирует и его — новые модели требуют явный `$table`.
- **RefundLedger** (`Payments\Domain`) — единственный владелец «сумма возврата из денег». `RefundService::refund()` делегирует через `evaluate()`; `refusal()` переводит `REASON_*` в код. Сторож — `PaymentWritePathTest` (over-balance 422 `REFUND_EXCEEDS_BALANCE`; partial → `partially_refunded`).
- **Slug**: единого `SlugGenerator` нет — `Artist::slugFor` (fallback `artist`), `SyncArtistsCommand::uniqueSlug` (исключает себя по `normalized_name`), `VenueController` (while-exists `-N`), `CatalogService` (транзиентный ключ) — разные правила, не сводить.
- **Соц-вход**: провайдеры — `Auth\Domain\SocialProvider` (`yandex|vk|telegram`), личность — `SocialIdentity`, Telegram `initData` — `Auth\Domain\TelegramInitData` (ключ HMAC `WebAppData`). `social_accounts` — app-only (в `$knownExtensions` + `APPLICATION_ONLY_TABLES`); `users.email`/`password` NULLABLE — санкционированный запас, соц-колонок в `users` НЕТ. Callback — `GET /auth/{provider}/callback` (web, 302), Telegram — `POST /api/v1/telegram/auth`.
- **Цена из чужой страницы** (импорт площадки) — только `Venues\Domain\ExternalPrice`:
  `fromMinorUnits()` (SSR-стейт, копейки) и `fromMajorAmount()` (JSON-LD, рубли строкой)
  → minor units + валюта в верхнем регистре. Наружу — minor units (ТЗ §3). Мусор от
  источника = `null`, не 500. Сторожа — `ExternalPriceTest`, `MoneyConversionSingleOwnerTest`
  (второй метод покрывает `app/Modules/Venues`).
- **HMAC — только `HmacSignature`**: `sign()`/`verify()` для провода, `digest(raw:true)` для
  своего формата (`QrSigner`), `deriveKey($material,$key)` когда результат сам служит КЛЮЧОМ
  следующего HMAC (Telegram `initData`: `deriveKey($botToken,'WebAppData')`). Сторож —
  `HmacSignatureCopyTest`.
- **`users.status`** — `Core\Users\Domain\UserStatus` (словарь `active|inactive|banned`);
  это НЕ `PublicationStatus` (у того нет `banned`). Сторож — `PublicationStatusCopyTest`.
- **Действующая цена узла схемы зала** — только `Venues\Domain\SchemaPrice`:
  `ofNode()` (`priceMinor` побеждает легаси `price`), `forRow()` (`rowPrices[ряд]` → сектор),
  `forStandingZone($obj, ?$sector)`, `seatOverride()` (индивидуальная цена места: мусор =
  «своей цены нет», наследуем ряд). Владеет ПРИОРИТЕТОМ и РАЗБОРОМ, не валидностью:
  `isSellable()` = `>= 1`, а строгость остаётся у границы (API строг, чтец БД терпим).
  Клиентское зеркало — `lib/hall.ts`: `nodePriceMinor`/`nodePriceOrNull`/`seatPriceOverride`/
  `effectiveSeatPrice` (+ словари `NODE_PRICE_KEYS`, `SEAT_PRICE_KEYS`). Сторожа —
  `SchemaPriceTest`, `SchemaPriceSingleOwnerTest`, `HallEditor/hallPrice.test.ts`.
- **Выпуск embed-ключа** — только `Embed\Services\EmbedTokenIssuer`: `issue()` (спрашивает
  `ApiKeyPolicy::issueDecision()` ДО записи, скоупы из `EmbedScope::all()` — не литерал),
  `revoke()` (необратимо), `generateToken()` (`nbem_` + 32 байта), `displayPrefix()`.
  В базе только `Sha256Hex::of($token)` ⇒ токен показывается ОДИН раз (как `DeviceTokenIssuer`).
  Сторож — `EmbedProvisioningTest`.
- **«Что считать хостом»** — только `Embed\Domain\EmbedOrigin`: `hostFrom()` (разбор) и
  `normalizeDomain()` (внесение в whitelist) — одно правило на проверку и на запись.
- **`embed_domains` НЕ имеет `public_id`** ⇒ `IdentifierLookup::firstOrFail()` там НЕЛЬЗЯ
  (запрос по несуществующей колонке = 500). Маршрут — `{embedDomain}` + `whereNumber()`,
  в спеке `type: integer`. У `api_keys` `public_id` есть — там `IdentifierLookup` уместен.
- **«Есть ли право X»** — только `UserRepository::hasPermission()` (через `UserService`).
  Идентификатор права — `permissions.slug` (`view_events`), НЕ `permissions.name` (русская
  подпись). Владелец «сотрудник ли это» — другой и грубее: `StaffRole::isStaff()` (slug роли
  ∈ admin/manager). Это два разных вопроса, не сводить. Сторож — `PermissionResolutionTest`.
  **Шлюз `permission` НЕ подключён ни к одному маршруту**, и подключать его «в один слой»
  нельзя: `RequirePermission` падает в `$user->hasPermission()`, которого у `User` нет ⇒
  отказ всем. Сначала слушатель хука `auth.user_has_permission` ИЛИ делегат на модели.
- **Размер страницы из запроса** (`per_page`/`limit`/`lines`) — только `Core\Support\Paging::limit()`:
  `max(1, min($max, …))`. Дефолт и потолок — ПАРАМЕТРЫ (медиа 24/100, админка чекина 50/100,
  отчёт сканов 50/200, логи 100/500, организации/залы/пользователи 15/100), не одна константа.
  Было 15 копий формулы в двух порядках аргументов И 5 мест, где клампа не было ВООБЩЕ
  (venues, sessions, halls, organizations, admin users — `?per_page=1000000` читал всю таблицу).
  Сторожа — `PagingTest` (формула + невакуумность: перепутанный порядок даёт другой результат),
  `VenueListPagingTest` (вызов владельца реально стоит в контроллере публичного каталога).
  НЕ путать с другими механизмами: рекомендации — `RecommendationPolicy::clampLimit()`;
  инвентарь — валидация запроса `max:500`. Другое правило: часы раннего доступа в
  `LoyaltySettingsService` (`max(1, min(MAX_HOURS, …))`, без дефолта) — иной смысл, не сюда.
- **Настройки лояльности: две нормализации — два владельца.** Окно раннего доступа
  `[1, MAX_HOURS]` — только `LoyaltySettingsService::clampHours()` (было 2 копии: чтение
  формы и запись). Сумма награды `[0, MAX_BONUS]` — только `ReferralPolicy::normalize()`
  (было 4 копии `max(0, …)` БЕЗ потолка в `LoyaltySettingsService` — обрезанная копия
  правила, которое уже жило в `ReferralPolicy`). HTTP-граница отклоняет выход за диапазон
  (422), но прямой вызов сервиса (сид/миграция/команда) обязан приводить сам.
  Сторожа — `ReferralCodeTest::test_normalize_owns_the_bonus_range`,
  `EarlyAccessTest::test_save_clamps_the_window_even_without_http_validation` (читает
  СЫРОЕ `settings.value_json`, а не `forAdmin()` — иначе не отличить «save записал кламп»
  от «прочитали клампнутым»). НЕ путать с `RaffleDraw::max(0, min($i,$j))` (перестановка)
  и `LoyaltyLevelService::max(0, …)` («заказов до уровня») — другие правила, одиночные.
- **«Выручка по дням» / ключ суток (фронтенд)** — только `resources/js/lib/stats.ts`:
  `revenueByDay()` + `localDateKey()`. Ключ — из ЛОКАЛЬНЫХ компонент, НЕ
  `toISOString().slice(0,10)` (UTC ⇒ съезд на сутки у браузера восточнее UTC; на UTC-машине
  невидим). Аналитика (14д, `paid_at ?? created_at`) и дашборд (12д, `created_at`) зовут одного
  владельца; окно и поле даты — параметры. Сторож — `tests/Unit/Admin/revenueByDay.test.ts`.
- **Подписи и цвета статусов (фронтенд)** — только `resources/js/lib/statusLabels.ts`:
  `statusMeta()` (подпись+тон+точка), `statusLabel()` (только текст), `statusOptions()` (опции
  `<select>`), `findStatusMeta()` (`null` для «нет такого статуса» — нужно подписи под QR билета).
  Девять наборов: order/ticket/event/session/hall/venue/payment/user/raffle. `NStatusBadge` —
  реактивная обёртка над словарём, своих словарей НЕ держит. Значения — из PHP-владельцев;
  паритет сверяет `tests/Unit/statusVocabularyParity.test.ts` (читает классы с диска), потому что
  `tools/verify-status-literals.php` видит только `app/`. Сторожа: `statusLabels.test.ts`
  (значения, порядок опций, тон рисуется `NBadge`, цвета не мимо палитры), `statusBadge.test.ts`
  (реактивность + все 7 состояний сеанса), `Storefront/raffles.test.ts`.
  ВАЖНО про сеанс: `held` — состояние ПОЗИЦИИ ИНВЕНТАРЯ, у сеанса его нет. Список —
  `SessionStateMachine::all()`: draft/scheduled/on_sale/sold_out/closed/completed/cancelled.
  Фронтенд держал старый список с `held` и после починки бэкенда: форма отправляла `held` → 422.
  Тип `SessionStatus` обязателен — без него поле снова станет `string` и компилятор промолчит.
  Цвета — только токены проекта (`sun` ожидание, `mint` успех, `rose` опасность, `brand` акцент,
  `sky` инфо). Дефолтная палитра Tailwind РАБОТАЕТ (`extend`, а не замена), но обходит словарь.
  НЕ путать с подписями СОБЫТИЙ уведомлений (`AdminNotificationTemplatesPage`: `order.paid` →
  «Заказ оплачен») — другой словарь, совпадение слов случайно; и с заголовками страниц
  (`PaymentResultPage`: «Оплата не прошла») — это проза, а не ярлык состояния.
- **«Адрес → ключ» (email/телефон)** — только `Modules\Users\Domain\ContactChannel`:
  `normalise(EMAIL|PHONE, …)` (и форма ЗАПИСИ, и форма сравнения) + `sameIdentity()`.
  Пространство имён `Users\Domain` историческое: правило принадлежит КОЛОНКЕ, поэтому им
  пользуются Auth, Orders, Loyalty, Notifications, Core\Users. Пять адресных колонок —
  `users.email`, `orders.customer_email`, `referrals.invited_email`,
  `newsletter_subscribers.email`, `social_accounts.email` — все `utf8mb4_unicode_ci`.
  Второй такой класс = вторая копия.
  Ловушка, из-за которой это тянет за собой деньги и авторизацию: `strcasecmp()` и
  `strtolower()` складывают регистр ТОЛЬКО в ASCII, а `!==` в PHP строже коллации.
  Живой дефект был в `UserService::updateUser()`: `!==` решал «изменился ли адрес», а
  `findByEmail()` (SQL) — «занят ли он»; свой же адрес в другом регистре давал 422
  «already registered» (путь `PATCH /me` И админка). Домен при этом уже считал адрес
  неизменённым — противоречили PHP, SQL и сервис разом.
  `users.email` раньше был ЕДИНСТВЕННОЙ колонкой, хранившей «как набрали» (остальные пишут
  нижний регистр) — теперь канонизируется на всех путях записи.
  `hash_equals()` в `AccountRecoveryService` оставлен намеренно (постоянное время) — там
  нормализуют оба операнда, а не заменяют сравнение на `sameIdentity()`.
  Сторож — `tests/Unit/UserIdentityTest.php::testNoOtherFileReducesAnAddressItself`
  (по `app/`: запрет `strcasecmp()` и `strtolower()`/`mb_strtolower()` над `*email*`;
  комментарии режутся токенайзером) + `testNonAsciiCaseIsFoldedTheWayTheCollationFoldsIt`.
  Живой сторож поведения — `MeEndpointsTest::test_patch_me_accepts_its_own_address_written_in_another_case`.
  ЧЕГО СТОРОЖ НЕ ВИДИТ: `trim($email)` без сложения регистра — половина правила.
  НЕ путать: `NotPersonalData` (`mb_strtolower` для поиска ПДн в пароле) и
  `PromoCodeService` (`mb_strtoupper` кода) — другие правила.
  ГРАНИЦА, проведённая сознательно: `orders.customer_email` НЕ канонизируется — адрес заказа
  это документ, на него уходят письма, и он не «ключ субъекта». Все, кому нужен ключ
  (`referrals`, сравнения, отправка копии), нормализуют его сами.
