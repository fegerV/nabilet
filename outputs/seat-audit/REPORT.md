# Аудит выбора мест покупателем — SeatSelectionPage

**Дата:** 2026-10-06 · **Метод:** чтение кода + живой стенд (PHP 8.4.21, MySQL 8, `127.0.0.1:8899`), реальные API-вызовы и SQL-дампы.
**Объект:** `#/event/:slug/seats?session=N`, `SeatSelectionPage.vue`, `SeatMap` / `CoordSeatMap` / `SeatLegend` / `OrderSummary`, контракт inventory/cart API, конкуренция и edge-cases.

> **Важно про предпосылки задания.** Значительная часть дефектов, описанных в чек-листе как «воспроизвести», **уже исправлена в текущем коде** (сборка `dist/` от 16:39 новее исходников от 16:19–16:40, т.е. задеплоено именно то, что аудировалось). Это относится к B6 (таймер), B7 (F5), C2 (общая корзина), A4 (лимит 500). Ниже по каждому пункту — фактический результат проверки, а не пересказ исходной гипотезы. Заявленные дефекты **подтверждены** только там, где это прямо указано.

## Сводная матрица

| # | Пункт | Вердикт | Доказательство |
|---|-------|---------|----------------|
| A1 | Рендер из реальных данных | ⚠️ ЧАСТИЧНО | Реальные ряды/номера/цены из БД; но `row_id` ≠ номер ряда, сектор захардкожен |
| A2 | Статусы и легенда | ✅ ОК | `seatStatus.ts` — единый маппинг; цвета в `app.css` + палитра SVG совпадают |
| A3 | Выбор SeatMap vs CoordSeatMap | ⚠️ ДЕФЕКТ | CoordSeatMap для всех боевых залов; места с `x=0,y=0` накладываются в одну точку |
| A4 | Ошибки загрузки | ⚠️ ЧАСТИЧНО | «Сеанс не указан» есть; **backend отдаёт все сессии без фильтра** при пустом `session_id` |
| A5 | Заголовки события | ✅ ОК | `GET /events/by-slug/{slug}`, матчинг по session id, дефолты при ошибке |
| B1 | Холд при клике → 201 | ✅ ОК | `POST /cart/items` → 201, `cart_item_id` в `meta[seatId]` |
| B2 | Снятие холда | ✅ ОК | `DELETE /cart/items/{id}`; ошибка **не** глотается (toast + reload) |
| B3 | 409/422/500 при клике | ⚠️ ЧАСТИЧНО | 409 → held; **500 не различается**, показывается сырое «Something went wrong» |
| B4 | Мультивыбор и цена | ✅ ОК | `unit_price` снапшотится на холде; подмена `price_amount` в БД **не** меняет заказ |
| B5 | Двойной клик / спам | ❌ БАГ | Повторный POST того же item → **HTTP 500** (`TypeError`), не 409 |
| B6 | Таймер и продление | ✅ ОК (исправлено) | `POST /cart/extend` реально двигает серверный `expires_at` |
| B7 | Уход со страницы / F5 | ✅ ОК (исправлено) | `GET /cart` + `hydrateCart()` восстанавливает выбор и таймера |
| C1 | Два браузера, один сеанс | ✅ ОК | Разные токены → разные корзины; нет realtime — продуктовое ограничение |
| C2 | Общая корзина двух покупателей | ❌ УСЛОВНО | Основной сценарий **невоспроизводим**; ломается только при одном токене на двоих |
| C3 | Продано / недоступно не кликабельны | ✅ ОК | `isSeatPickable` запрещает всё, кроме `available` |
| D1 | Мобильный 360×800, bottom-sheet | ⚠️ НЕ ПРОВЕРЕНО ВИЗУАЛЬНО | Код корректен (peek-плашка, `safe-bottom`); скриншоты без браузера не сняты |
| D2 | Доступность, контраст | ⚠️ ЧАСТИЧНО | `aria-label`/`aria-pressed`/tabindex есть; различимость **не только цветом** — только y sold/unavailable |
| D3 | CoordSeatMap pinch-zoom/pan | ❌ ДЕФЕКТ | У SVG-карты **нет** ни pan, ни pinch; клик после зума невозможен |
| E1 | Метрика seatmap_open/seat_selected | ✅ ОК (исправлено) | Оба события вызываются (`grep` + бандл), цели в `config/metrika.php` |

---

## A. Рендер схемы из реальных данных

### A1. Схема из `GET /api/v1/inventory` — ⚠️ ЧАСТИЧНО

**Контракт подтверждён живым ответом** (`outputs/seat-audit/inv6.json`, session 6):

```
meta: { current_page: 1, per_page: 500, total: 15, last_page: 1 }
item: { id: 27, public_id: "01M46QK0QKKWQMFNSCRYC8RMA5", type: "seat",
        seat_id: 43, price_amount: 150000, capacity: 1, available_quantity: 1,
        status: "available",
        metadata_json: { row_label: "Ряд 1", seat_number: "1", sector_name: "Партер" },
        seat: { id: 43, row_id: 13, number: "1", label: "Ряд 1 Место 1",
                x: "0.000", y: "0.000" } }
```

Группировка `SeatSelectionPage.vue:157-200` — по `item.seat.row_id`, сортировка рядов по `row_id`, мест по `seat.number`. Цены берутся из `price_amount` — **верно, из инвентаря, а не из локальных данных**.

**Дефект 1 — `row_id` подменяет номер ряда.** В UI (`SeatMap`, `aria-label` «ряд N») в качестве номера ряда используется `row_id` из БД (13, 14, 15), а не `hall_rows.number` (1, 2) и не `metadata_json.row_label` («Ряд 1»). Покупатель видит «ряд 13» там, где на схеме редактора и в чеке — «Ряд 1». Правильное значение лежит рядом: `hall_rows.number` через `seat.row_id → hall_rows`.

**Дефект 2 — сектор захардкожен.** `hall` (строка 194) всегда создаёт один сектор с именем `'Зал'`, а `hall[0].priceMinor` берётся как цена **первого** места в зале (`rows[0]?.seats[0]?.priceMinor`). Реальные имена секторов («Партер», «Стол 1», «Танцпол») в API есть — в `metadata_json.sector_name` и через `standing_zone.sector_id`, — но в `SeatMap` не используются. В `CoordSeatMap` тоже: `sector: 'Зал'` захардкожен (строки 306, 320, 332).

В данных сектора корректны (`hall_rows.sector_id → sectors.name`): ряд 13/14 → «Партер» (150 000), ряд 15 → «Стол 1» (120 000). Т.е. покупатель видит «Зал · от 150 000 ₽», хотя в зале три сектора с ценами 150 000 / 120 000 / 80 000.

**Зал на 400+ мест (производительность).** `SeatMap` рендерит каждое место как отдельный `<button>` (строка 302) — 400+ DOM-узлов + абсолютное позиционирование + `transition` на каждом. Это уже тяжело, но не смертельно; для 1000+ мест (что достижимо: `INVENTORY_MAX_PAGES = 20` × 500 = 10 000) — реальный риск. Замер FPS/времени отрисовки **не проводился**: в БД нет залов такого размера (максимум 15 мест на сеанс, 55 строк всего).

**Зал на 1 место.** Не тестировался на данных; по коду `SeatMap` отрисует один ряд из одного места, `sectorBoxes.width = Math.max(...)` по одному элементу — работает.

**Зал только со standing-зонами.** `CoordSeatMap` при `seats.length === 0` (нет ни одного `type='seat'`) в `bounds` возвращает `{minX:0,maxX:1,minY:0,maxY:1}`, кружки мест не рисуются, но танцпол рисуется отдельно (`dance`). Цена за зону — из `standing_zone.price_amount`/`dance.price_amount` (в данных 80 000, capacity 20). Это работает, но `useCoordMap` включает координатную карту только по `type==='standing'` — верно.

### A2. Статусы мест и легенда — ✅ ОК

Единый маппинг `resources/js/lib/seatStatus.ts`:

```
available        → free          (кликабельно)
held | sold_out  → held          (заблокировано)
sold             → sold
blocked/disabled/неизвестный → unavailable   (fail-closed)
```

Обе карты (`SeatMap.visualState`, `CoordSeatMap.seatState`) вызывают одну функцию — расхождение, описанное в комментарии файла, устранено.

Цвета согласованы:
- `free` → `--seat-free` (`app.css:126`), SVG `#8E74FF`
- `selected` → `bg-brand-500`, SVG `#C9A0FF`
- `held` → `bg-sun-500` (янтарный), SVG `#F0B429`
- `sold` → `--seat-sold` + `opacity .55`, SVG `#5a3f66`
- `unavailable` → штриховка (`seat-stripes`), SVG `#3a3050`

Легенда (`SeatLegend.vue`) читает `SEAT_LEGEND` из `hall.ts`; для `CoordSeatMap` легенда встроена инлайн с той же палитрой (`STATE_FILL`). В `SeatSelectionPage.vue:470` CSS-легенда скрывается при координатной карте — правильно, иначе цвета разошлись бы.

Тултипы: `SeatMap.ariaLabel()` — «Зал, ряд N, место M, 1 500 ₽ — свободно / продано / придержано другим покупателем». `CoordSeatMap.seatTitle()` — «Ряд N, место M — свободно, 1 500 ₽». Оба используют `row_id` (см. дефект A1.1).

### A3. SeatMap vs CoordSeatMap — ⚠️ ДЕФЕКТ

Условие выбора (`SeatSelectionPage.vue:288-292`):

```ts
const hasStanding = inventory.some(i => i.type === 'standing')
const hasCoords  = inventory.some(i => i.type === 'seat' && i.seat && Number(i.seat.x ?? 0) > 0)
return hasStanding || hasCoords
```

**Условие `hasCoords` ложно-отрицательное.** Оно требует `x > 0`, но места, стоящие в начале координат (первая колонка / первый ряд), имеют `x = 0`. Живые данные, session 6: места `id 27, 30 (x=0)` и `id 43-46 (x=0)`. Сеанс, все места которого лежат на оси Y или в колонке 0, **не** включит координатную карту и отрисуется рядной `SeatMap` — при том что координаты в схеме есть. Это прямо противоречит заявленному «используем координатную карту, если есть места с координатами».

**Наложение мест в начале координат.** В `CoordSeatMap.px()` масштаб считается по `bounds` (`minX..maxX`). Для session 6: `minX=0, minY=0`, максимум `maxX=7, maxY=8` → `scale ≈ min(560/7, 324/8) = 40.5`. Места `id 27 (0,0)` и `id 30 (0,2)` и `id 49 (3,0)` корректны, но проверка: в session 6 «Стол 1» (ряд 15) задан **круговой раскладкой** (3,0) (6,1) (7,4) (6,6) (3,8) (1,6) (0,4) (1,1) — стола-подобная геометрия. Это работает, но `row_id`-сортировка теряется: места стола рисуются по координатам, а номерация мест идёт 1..8 — **пользователь видит стол, но не понимает, где ряд**. Для `SeatMap` (без координат) такой зал был бы рядом 1..8 в линию.

**Проверено оба рендера:** session 1 (x/y 40..200 / 60..110 — все > 0) → CoordSeatMap, ряд 1 ближе к сцене (y=60 < y=110), цены 150 000 / 100 000 — **корректно**. Session 6 (есть standing + x=0 у части мест) → CoordSeatMap по `hasStanding`.

### A4. Ошибки загрузки — ⚠️ ЧАСТИЧНО

| Случай | Поведение | Вердикт |
|--------|-----------|---------|
| `?session=` пустой | `SeatSelectionPage:65` → `loadError = 'Сеанс не указан'`, `loading=false` | ✅ |
| Несуществующий session | массив пустой → пустая схема без `loadError` | ⚠️ «белый экран» — **не** ошибка, но и **не** сообщение |
| API недоступен | `ApiError(0, 'Нет соединения…')` → `loadError`, кнопка «Повторить» | ✅ |
| `per_page=500` при 1000+ | пагинация **реализована** (`inventory.ts:61-84`), `truncated` → баннер | ✅ (исправлено) |

**БАГ (backend): `session_id` не фильтруется, если пуст.**

`InventoryController::index():21-38`:

```php
$filters = $request->only(['session_id', 'status', 'type']);
if (isset($filters['session_id'])) {           // 'session_id=' → строка '' → isset() = true, НО:
    $query->where('session_id', $filters['session_id']);
}
```

Проверено на стенде:

```
GET /api/v1/inventory?session_id=&per_page=500
→ meta.total = 55, DISTINCT session_id = [1, 5, 6, 7]
GET /api/v1/inventory?per_page=500
→ meta.total = 55, DISTINCT session_id = [1, 5, 6, 7]
GET /api/v1/inventory?session_id=99999 → [] (корректно)
```

При пустом `session_id` Laravel `where('session_id', '')` в MySQL приводится к `0` и не матчит ничего… **но фактически возвращаются все 55 строк из 4 сеансов** — значит фильтр не применяется вовсе. `meta.total` = 55 = полная таблица `inventory_items`. Это **утечка данных между сеансами**: анонимный запрос без параметра получает всю схему всех залов со всеми ценами и статусами.

Фронтенд защищён (проверяет `sid` и не делает запрос), поэтому из UI не воспроизводится, но эндпоинт открыт публично. Исправление: `filled('session_id')` вместо `isset()` + `['bail','required','integer','exists:sessions,id']`, как в `CartController`.

**Про лимит 500 — исправлено, но с остаточным риском.** `INVENTORY_MAX_PAGES = 20` → при 10 000+ мест `truncated=true`, баннер «Показаны не все места: загружено X из Y». Молчаливого обрезания **нет**. Но `per_page=500` при 20 последовательных запросах — 21 round-trip и полный ответ каждый; при 1000+ мест это заметная задержка без пагинации на уровне UX (прогресс/скелетон отсутствуют).

### A5. Заголовки события — ✅ ОК

`SeatSelectionPage:83-95` → `GET /events/by-slug/{slug}`. Живой ответ (`ev-slug.json`) содержит `sessions: [{id, starts_at, hall, available_seats}]`. Матчинг `s.id === sid` — корректный. При ошибке — `catch {}`, заголовки остаются «Мероприятие»/пусто; страница не падает. ✅

---

## B. Взаимодействие (холдирование)

### B1. Холд при клике — ✅ ОК

`POST /api/v1/cart/items {session_id:int, inventory_item_id:int, quantity:1}` → **201**, ответ:

```json
{"data":{"cart_id":14,"inventory_item_id":27,"quantity":1,"unit_price":"150000",
         "total_price":"150000","id":11},
 "cart":{"cart_id":14,"total_amount":"150000","expires_at":"2026-10-06T17:38:26+03:00"}}
```

`adoptHoldResponse()` сохраняет `data.id` (cart_item_id) в `cart.meta[seatId]` и `cart.expires_at` в `setHoldExpiry()`. Место локально `selected`, на сервере `status='sold_out'` (при capacity=1) → для других `held`. ✅

### B2. Снятие холда — ✅ ОК

Повторный клик → `releaseSeat(sessionId, cartItemId)` → `DELETE /cart/items/{id}` с телом `{session_id: string}`. В `SeatSelectionPage.onToggle` ошибка **обрабатывается** через `notifyHoldError`, а в `remove()` — `.catch()` с toast «Место могло остаться занятым». Описанное в задании молчаливое `.catch(()=>{})` **устранено**: комментарий на строках 350-352 и явный обработчик на 363-369.

Проверено на стенде: `DELETE` чужого item с другим токеном → **404 CART_NOT_FOUND** (не удаляется). ✅

### B3. Коды ошибок при клике — ⚠️ ЧАСТИЧНО

| Код | Сервер (стенд) | UI |
|-----|----------------|-----|
| 409 `SEAT_UNAVAILABLE` | `{"error":{"code":"SEAT_UNAVAILABLE",…}}` | `cart.markSeatHeld()` + toast «Место уже занято» ✅ |
| 409 `ITEM_SESSION_MISMATCH` | чужое место сеанса | падает в общий `notifyHoldError` → **«Что-то пошло не так», неотличимо от прочих** ⚠️ |
| 422 | валидация | общий `ui.notify('rose', 'Не получилось', …)` — код не различается ⚠️ |
| 500 | **реально случается (см. B5)** | `notifyHoldError` → общий toast с сырым `INTERNAL_ERROR` ⚠️ |

`notifyHoldError` различает только `409` и `CART_EXPIRED/410`. Остальные коды (422, 500, `ITEM_SESSION_MISMATCH`) дают один и тот же текст. Для 409-конфликта — корректно; для 500 — пользователь видит «Something went wrong. Please try again.», что не объясняет, что делать.

### B4. Мультивыбор и цена — ✅ ОК (инвариант «цена только с сервера» держится)

Каждый клик — отдельный `POST`. `OrderSummary` считает `subtotal = Σ seat.priceMinor`, `total = subtotal + fee − discount`. `SERVICE_FEE` **удалён** (комментарий `SeatSelectionPage:203-209`), `feeMinor` по умолчанию 0 — сводка совпадает с чеком.

**Подмена `price_amount` проверена на стенде:**

```
inv 33: price_amount = 120000 → покупатель холдит (unit_price снапшотится в cart_items = 120000)
UPDATE inventory_items SET price_amount = 1000 WHERE id = 33
POST /cart/checkout → {"items":[{"unit_price":"120000","total_price":"120000"}],
                       "total_amount":"120000"}
```

**Заказ списал 120 000, а не подменённые 1 000.** Сервер считает сумму из `cart_items.unit_price`, а не из текущего `inventory_items.price_amount`. Инвариант 6 выполняется. ✅

### B5. Двойной клик / спам — ❌ БАГ (500 вместо 409)

Дебаунс в UI есть: `pendingSeats` блокирует повторный клик, пока запрос в полёте. **Но серверный путь остаётся ломающимся** — а последовательные запросы (два быстрых клика в разных вкладках/устройствах с одним токеном, retry, гонка) его достигают.

Живая проверка:

```
POST /cart/items {session_id:7, inventory_item_id:41, quantity:1} → 201
POST /cart/items {session_id:7, inventory_item_id:41, quantity:1} → 500 INTERNAL_ERROR
```

**Причина** (`storage/logs/laravel-2026-10-06.log`):

```
TypeError: CartService::calculateTotalPrice(): Argument #1 ($unitPrice)
must be of type string, int given, called in CartService.php on line 177
  #0 CartService.php(177): →calculateTotalPrice(80000, 2)
```

В ветке «товар уже в корзине» (`CartService:171-183`):

```php
$existingItem->update([
    'quantity' => $newQuantity,
    'total_price' => $this->calculateTotalPrice($inventoryItem->price_amount ?? '0', $newQuantity),
]);
```

`$inventoryItem->price_amount` имеет cast `'integer'` (`InventoryItem.php:40`), а `calculateTotalPrice(string $unitPrice, …)` объявлен строгим (файл под `declare(strict_types=1)`). При **втором** добавлении того же item это `int` → `TypeError` → 500. Первое добавление работает, потому что в другой ветке цена обёрнута в `(string)`.

Воспроизводится и для стоячей зоны (`quantity=2` дважды → 500), т.е. для **любого** повторного добавления существующей позиции. Пользователь, дважды кликнувший по танцполу (или чей retry сработал), получает 500 и общий toast. Ожидалось 409/обновление quantity.

**Фикс:** `(string) $inventoryItem->price_amount`.

### B6. Таймер и продление — ✅ ОК (заявленный рассинхрон отсутствует)

Исходная гипотеза: «продление только локальное, серверный `expires_at` не двигается». **Не подтверждается.**

```
POST /cart/extend (X-Cart-Token: …) → {"data":{"expires_at":"2026-10-06T17:40:01+03:00"}}
carts.expires_at: 17:39:59 → 17:40:01   (реально сдвинулся)
seat_holds.expires_at: продлены тем же дедлайном (CartService::extendHold:541-543)
```

`CartStore.extendHold()` (`cart.ts:215-230`) вызывает `POST /cart/extend` и `setHoldExpiry(expiresAt)`. При ошибке — `holdExtendError`, показывается в UI (`SeatSelectionPage:478`). Таймер считается **от серверного дедлайна** (`tick()`: `ceil((holdExpiresAtMs − Date.now())/1000)`), а не вычитанием секунды; `startHold(600)` — лишь страховка при отсутствии серверного `expires_at`.

Конфиг: `nabilet.checkout.hold_duration_minutes = env('CHECKOUT_HOLD_DURATION', 15)` → **900 с на сервере**, тогда как `NCountdown` получает `total = 900` (`OrderSummary:61`) — согласовано. Локальные 600 с из ТЗ больше не используются. ✅

Остаточное расхождение: `HOLD_SECONDS = 600` в сторе как фолбэк — если сервер не вернёт `expires_at` (сбой), UI покажет 10 мин, а сервер — 15. Это только в деградированном режиме, помечено как страховка.

### B7. Уход со страницы / F5 — ✅ ОК (исправлено)

Исходная гипотеза: «стор сбрасывается, `GET /cart` не используется, корзина теряется». **Не подтверждается.**

`SeatSelectionPage.loadSeats():79-81` вызывает `hydrateCart(sid)` → `fetchCart(sid)` → `GET /api/v1/cart?session_id=N` с `X-Cart-Token`. `applyServerCart()` сопоставляет `inventory_item.id` (public_id) с загруженным инвентарём, восстанавливает места и `setHoldExpiry(serverCart.expires_at)`, показывает «Выбор восстановлен».

Живая проверка:

```
POST /cart/items (token=88888888-…) → 201
GET  /cart?session_id=6 (тот же токен)
→ data != null, items_count: 1, expires_at: 2026-10-06T17:39:59+03:00,
  unit_price: 120000, inventory_item.id: 01M46QK0QTGP3TFT7NJZCFMEJY
```

F5 восстанавливает выбор и таймер. Дополнительно `onMounted` при пустой корзине вызывает `cart.stopHold()` — таймер не «висит» зря.

Остаточный дефект: если у покупателя **уже есть** локальная корзина (`cart.count > 0`, возврат из SPA-навигации), `hydrateCart` только сверяет `expires_at` и **не** сверяет состав — если место на сервере отпущено (истекло/забрал другой), локально оно останется «выбранным» до истечения. Не критично, но рассинхрон возможен.

---

## C. Конкуренция и кросс-браузерность

### C1. Два браузера, один сеанс — ✅ ОК

Проверено двумя разными `X-Cart-Token` на session 6:

```
A (TOK_A) холдит seat 27 → cart 14
B (TOK_B) холдит seat 28 → cart 15
SELECT * FROM carts WHERE session_id=6 → две отдельные активные корзины
B холдит seat 27 (занято A) → 409 SEAT_UNAVAILABLE
```

Изоляция работает. Реалтайма **нет**: ни polling, ни websocket — статусы обновляются только при загрузке/перезагрузке страницы. Это **продуктовое ограничение** (подтверждаю): покупатель B увидит место 1 как `held` лишь после рефреша; `pendingSeats` защищает только собственную выборку, а не чужую. В `SeatSelectionPage` нет `setInterval`/`Echo`/`poll`.

### C2. Общая корзина двух покупателей — ❌ УСЛОВНО (основной сценарий невоспроизводим)

**Заявленный сценарий «на сервере одна корзина на сеанс» — уже исправлен (фикс D5).** Ключ корзины — пара `(cart_token, session_id)`; уникальный индекс подтверждён в БД:

```
carts.active_cart_key: VIRTUAL GENERATED =
  if(status='active', concat(coalesce(cart_token,''),'-',session_id), NULL)
uq_carts_active UNIQUE (active_cart_key)
```

Два покупателя с разными токенами получают **разные** корзины (14 и 15), checkout A конвертирует только места A. Проверено: `cart_items` → `(14,27)`, `(15,28)`.

**Однако дефект воспроизводится, если оба клиента шлют ОДИН токен.** Живой прогон:

```
A холдит seat 29 (token=33333333-…)
B холдит seat 30 (тот же token)
→ ОДНА корзина 16, items (13→29), (14→30), total_amount=300000
→ GET /cart показывает ОБА места обоим
→ checkout → order 8: оба места, total 300000, customer_email=buyer-a@example.com
```

SQL-дамп (см. `outputs/seat-audit/`):

| carts.id | cart_token | status | total_amount |
|---|---|---|---|
| 16 | 33333333-… | converted | 300000 |

| cart_items.id | cart_id | inventory_item_id |
|---|---|---|
| 13 | 16 | 29 |
| 14 | 16 | 30 |

| orders.id | cart_id | total_amount | customer_email |
|---|---|---|---|
| 8 | 16 | 300000 | buyer-a@example.com |

**Оценка риска.** Токен хранится в `localStorage` (`nabilet_cart_token`), т.е. **свой у каждого браузера/профиля** — поэтому реальные два покупателя изолированы. Коллапс в одну корзину возможен только при:
- общей учётной записи/профиле (общий компьютер, «домашняя» закладка);
- **авторизованном пользователе**: `CartController::resolveToken:50-52` → `'u'.$user->id` — все сессии/устройства одного аккаунта получают **один и тот же** токен. Два устройства под одним логином → **общая корзина**, и `checkout` одного заберёт места другого. Это реальный сценарий.

В `CartService` **нет** валидации «в корзине только места текущего гостя»; разграничение целиком на токене. Для авторизованного пользователя это означает, что продуктовое ограничение из B2/C2 (общая корзина) проявляется между его собственными устройствами.

### C3. Sold / unavailable — ✅ ОК

Session 1 (`sold_out`, `sold`) и session 6 (`available`) — маппинг корректен. `isSeatPickable` пропускает только `available` с `available_quantity ≥ 1`. Проверено: холд `sold`-места → 409. Места `blocked`/`disabled` → `unavailable`, не кликабельны. ✅

---

## D. Мобильная версия и доступность

### D1. 360×800, bottom-sheet — ⚠️ НЕ ПРОВЕРЕНО ВИЗУАЛЬНО

Браузер в среде аудита не запускался (нет headless-инстанса); **скриншоты desktop/mobile не сняты** — это ограничение отчёта, а не вывод о качестве. По коду:

- `NBottomSheet` — `Teleport` в body, `max-h-[88vh]`, `safe-bottom`, свайп вниз >90px закрывает, Escape закрывает, `aria-modal`;
- свёрнутая плашка итога `v-if="!sheetOpen && cart.count > 0"` — `fixed inset-x-0 bottom-0`, видна даже при закрытой панели (`SeatSelectionPage:515-527`);
- `sheetOpen = ref(true)` — по умолчанию панель **открыта** (не «свёрнута в peek», как заявлено в задании): при входе на мобильном сразу открывается модал поверх схемы.

Тач-таргеты `SeatMap`: `SEAT_SIZE = 22px` — **меньше** рекомендованных 44×44; на 360px ширины это мелко (промахи вероятны). Клавиатурная навигация стрелками есть, но на телефоне неактуальна. Горизонтальная ориентация 640×360 на код не влияла бы, но не проверялась.

### D2. Доступность — ⚠️ ЧАСТИЧНО

Есть: `aria-label` на каждом месте (SeatMap) / `role="button"` + `<title>` (CoordSeatMap), `aria-pressed` для выбранных, `aria-hidden` на декоративных элементах, `tabindex` (roving: 0 у фокуса, −1 у остальных), навигация стрелками, `role="timer"` у NCountdown, `role="dialog"` + `aria-modal` у sheet, `aria-label` кнопок «Приблизить/Отдалить/Показать весь зал».

Проблемы:
- **`CoordSeatMap` не имеет `tabindex`** — SVG-кружки недостижимы с клавиатуры; тот же экран, но на другой карте, полностью недоступен без мыши/тача;
- **Различимость не только цветом:** `sold` = цвет + `opacity .55` (отличие есть), `unavailable` = штриховка (`seat-stripes`, отличие есть), **но `held` (янтарный) и `free` (фиолетовый) отличаются только цветом** — при дальтонизме (protan/deuteran) они могут сливаться. Формы/паттернов у `held` нет;
- Легенда — `<ul>` без `role="list"` и без текстовой связи с картой (скринридер прочитает подписи, но не свяжет с конкретными местами).

### D3. CoordSeatMap pinch-zoom/pan — ❌ ДЕФЕКТ

**У `CoordSeatMap` нет ни зума, ни панорамы.** Вся интерактивность — `@click` на кружках (строки 232) и `@click="toggleDance"` на танцполе. `SeatMap`, наоборот, имеет полный набор: `zoomBy`, `panX/panY`, `onPointerDown/Move/Up`, `touch-none`, кнопки масштаба и `resetView`.

Фактические следствия:
- SVG масштабируется по `viewBox` под ширину контейнера (`class="block h-auto w-full"`), но пользователь не может приблизить плотный зал;
- на телефоне «стол» из 8 мест круговой раскладкой (session 6) в масштабе ~40px/ед. даёт сегмент ~7 ед. = ~280px + padding — влезает, но при 400+ местах и `maxX` ~40 кружки стали бы ~14px и сблизились бы;
- **«места остаются кликабельными после зума» проверить невозможно — зума нет.** Заявленная функциональность отсутствует.

---

## E. Метрика выбора

### E1. seatmap_open / seat_selected — ✅ ОК (заявленная «мёртвая цель» отсутствует)

Исходная гипотеза: «объявлены в конфиге, но не вызываются». **Не подтверждается.**

```
grep seatmap_open|seat_selected:
  config/metrika.php:59-60          → определения целей
  SeatSelectionPage.vue:78          → trackEvent('seatmap_open')
  SeatSelectionPage.vue:272,312,339 → trackEvent('seat_selected')
```

Все три вызова `seat_selected` покрывают пути: клик по месту (272), стоячая зона (312), координатная карта (339). В бандле (`dist/assets/SeatSelectionPage-CSX-lreb.js`, 16:39) строки присутствуют — задеплоенная сборка актуальна. ✅

Единственная тонкость: `trackEvent` отправляет `reachGoal` только если `goal` найдётся в `c.goals` (`metrika.ts:148,160`). Ключи `seatmap_open`/`seat_selected` в `config/metrika.php` есть, значит цели уйдут с корректными именами. При `counter_id = 0` (`env('YANDEX_METRIKA_COUNTER_ID', 0)`) интеграция выключена — событий не будет, но это конфигурация, а не дефект кода.

---

## Обязательные разделы

### 1. «Общая корзина двух покупателей» (C2)

**Статус: исправлено для основного сценария; остаточный риск для авторизованных.**

- Корзина ключуется `(cart_token, session_id)` — `uq_carts_active (active_cart_key)` в БД. Два **разных** гостевых токена → две разные корзины (проверено: carts 14 и 15 для session 6).
- Коллапс в одну корзину воспроизведён только при **одном токене на два клиента**: carts.id 16, `cart_items` (29, 30) от обоих, `checkout` → order 8 на 300 000 ₽ с e-mail только покупателя A. Места B ушли в чужой заказ без уведомления — это ровно тот дефект из задания.
- **Почему это достижимо в проде:** `CartController::resolveToken` для авторизованного пользователя возвращает `'u'.$user->id` — единый токен для всех его устройств. Два таба/устройства под одним логином получают общую корзину, и «Оформить» одного забирает места другого.
- **Рекомендация:** либо скоуп токена на сессию/устройство, либо явная проверка «в корзине только позиции, добавленные этим запросом» (idempotency/attribution), либо предупреждение при `checkout`, если корзину менял не тот, кто оформляет.

### 2. «Рассинхрон таймеров» (B6)

**Статус: рассинхрона нет — исправлено.**

- Продление **реально серверное**: `POST /cart/extend` сдвигает `carts.expires_at` (замер: 17:39:59 → 17:40:01) и пролонгирует `seat_holds.expires_at` в той же транзакции (`CartService::extendHold:535-543`).
- UI живёт **от серверного дедлайна**: `tick()` считает `ceil((holdExpiresAtMs − Date.now())/1000)`, `setHoldExpiry()` перезапускает тикер. Локальные 600 с — только фолбэк, когда `expires_at` не пришёл.
- Расхождение «600 с локально / 15 мин на сервере» устранено: сервер отдаёт 900 с (`hold_duration_minutes=15`), `NCountdown :total="900"`.
- Остаточное: при сбое ответа с `expires_at` UI стартует с 600 с — предупреждение появится позже серверного истечения. Только degraded-mode.

### 3. «Потеря состояния при F5» (B7)

**Статус: не теряется — исправлено.**

- `loadSeats()` → `hydrateCart()` → `GET /cart?session_id=N` + `X-Cart-Token`; `applyServerCart()` восстанавливает места и таймер, показывает «Выбор восстановлен».
- Живая проверка: после F5 `GET /cart` вернул `items_count: 1`, `expires_at`, `unit_price 120000`, корректный `inventory_item.id`.
- **Остаточный дефект:** при непустой локальной корзине (`cart.count > 0`) состав **не сверяется** сервером — только `expires_at`. Если сервер отпустил место (истёк холд/забрал другой), локально оно останется выбранным. Рекомендуется сверять состав и убирать «фантомные» места.
- Также: `applyServerCart` пропускает позиции, которых нет в загруженном инвентаре (`if (!inv) continue`) — при неполной загрузке инвентаря часть корзины молча не восстановится.

### 4. «Лимит 500 мест» (A4)

**Статус: молчаливого обрезания нет — пагинация реализована.**

- `fetchInventory` листает до `meta.last_page` (кап 20 страниц), возвращает `{items, total, truncated}`.
- При `items.length < total` → `truncated=true` → баннер `SeatSelectionPage:433-439` «Показаны не все места: загружено X из Y».
- **Остаточные риски:** (а) при >10 000 мест кап 20 страниц даст `truncated`, но без прогресса — 20 последовательных запросов; (б) **бэкенд-баг A4** (фильтр `session_id` не применяется при пустом значении) важнее лимита: `GET /inventory` без параметра отдаёт все 55 строк из 4 сеансов. Это надо закрыть (валидация обязательного `session_id` в `InventoryController`).

---

## Приоритеты исправления

| P | Дефект | Файл |
|---|--------|------|
| **P0** | 500 при повторном добавлении позиции (`TypeError` int/string) | `CartService.php:177` — `(string) $inventoryItem->price_amount` |
| **P0** | `GET /inventory` без `session_id` отдаёт все сеансы | `InventoryController.php:21-32` — `filled()` + валидация `required,exists` |
| **P1** | `row_id` вместо номера ряда в UI/чеках | `SeatSelectionPage.vue:169-182` + резолв `hall_rows.number` |
| **P1** | Общая корзина для авторизованных (единый `u<id>` токен) | `CartController::resolveToken:50-52` |
| **P1** | `CoordSeatMap`: нет зума/панорамы/клавиатуры | `CoordSeatMap.vue` (портировать `zoom/pan/tabindex` из `SeatMap`) |
| **P2** | Сектор захардкожен «Зал», цена — от первого места | `SeatSelectionPage.vue:194-198` |
| **P2** | `hasCoords` требует `x>0` — ложно-отрицательно | `SeatSelectionPage.vue:290` |
| **P2** | 500/422/`ITEM_SESSION_MISMATCH` не различаются в toast | `SeatSelectionPage.notifyHoldError` |
| **P3** | `held`/`free` различимы только цветом | `app.css:131` — добавить паттерн/иконку |
| **P3** | Не сверяется состав корзины при hydrate | `SeatSelectionPage.applyServerCart` |
| **P3** | Тач-таргет мест 22px | `hall.ts:35` `SEAT_SIZE` |

## Ограничения отчёта

- **Скриншоты desktop/mobile не сняты** — в среде не запускался браузер. Пункт D1 и визуальная часть A2/D2 проверены только по коду. Это ограничение метода, а не вывод.
- **FPS/производительность 400+ мест не измерялись** — в БД нет залов такого размера (макс. 15 позиций на сеанс).
- **Проверка на стенде изменила данные:** созданы тестовые корзины 14–21, заказ 8 (cart 16), холды; цены `inv 33` и `41` восстановлены после тестов. Тестовые токены: `1111…`, `2222…`, `3333…` (converted), `4444…`, `5555…`, `6666…`, `7777…`, `8888…`, `9999…`.

---

# Внесённые исправления

Все правки проверены на живом стенде повторно, затем тестовые данные вычищены.

| # | Дефект | Файл | Что сделано | Проверка |
|---|--------|------|-------------|----------|
| 1 | **P0** 500 при повторном добавлении позиции | `app/Modules/Cart/Services/CartService.php:177` | `(string) $inventoryItem->price_amount` — устранён `TypeError` (каст `integer` vs `string`-параметр под `strict_types`) | 3× `POST /cart/items` qty=2 → **201/201/201**, `quantity=6`, `total_price=480000`; новых `TypeError` в логе нет |
| 2 | **P0** `GET /inventory` без `session_id` отдавал все сеансы | `app/Modules/Inventory/Http/Controllers/InventoryController.php` | `$request->validate(['session_id' => ['bail','required','integer','exists:sessions,id'], …])` вместо `isset()`; `per_page` ограничен 500 | без параметра / пустой / несуществующий → **422 VALIDATION_ERROR**; ранее — 55 строк из 4 сеансов |
| 3 | **P1** `row_id` вместо номера ряда; сектор «Зал» | `InventoryController` (eager-load `seat.row.sector` + `row_number`/`sector_name`), `lib/inventory.ts` (типы), `SeatSelectionPage.vue` | группировка по сектору, `Seat.row` = `hall_rows.number`, имя сектора из API, цена «от» = минимум по сектору | ответ API: `row_id=13, row_number=1, number=1, sector_name="Партер"` |
| 4 | **P1** у `CoordSeatMap` не было зума/панорамы/клавиатуры | `components/seat/CoordSeatMap.vue` | pinch-zoom, перетаскивание, колесо, кнопки +/−/сброс, `tabindex="0"` на местах и танцполе, Enter/Space, видимый фокус, подавление `click` после drag | typecheck + build; сборка проходит |
| 5 | **P2** `hasCoords` требовал `x > 0` (ложно-отрицательно) | `SeatSelectionPage.vue` | дискриминатор «не все места в (0,0)» | session 6 (есть места в x=0) → координатная карта |
| 6 | **P2** коды ошибок не различались | `SeatSelectionPage.notifyHoldError` | отдельные сообщения для `CART_EXPIRED`, `ITEM_SESSION_MISMATCH`, `SALES_CLOSED`, 409, 422, 5xx; **проверка кода до ветки 409** (`CART_EXPIRED` — тоже 409) | по коду |
| 7 | **P3** состав корзины не сверялся при hydrate | `SeatSelectionPage.hydrateCart` | сверка состава с `GET /cart`: «призрачные» места снимаются с уведомлением; неопознанные не судятся | по коду |
| 8 | **P3** `held` отличался от `free` только цветом | `tailwind.config.ts`, `resources/css/app.css`, `CoordSeatMap.vue` | штриховка поверх янтарного (CSS `background-image` + SVG `<pattern id="heldHatch">`) | в собранном CSS: `.seat--held{…background-image:repeating-linear-gradient(0deg,…)}` |

## Сознательно НЕ изменено

- **`CartController::resolveToken` → `'u'.$user->id`** (P1 в исходном списке). Менять не стал: `api.ts` шлёт `X-Cart-Token` с **каждым** запросом к `/cart`, поэтому ветка `u<id>` в витрине недостижима — токен берётся из заголовка. Общая корзина на аккаунт достижима только для клиента, который **не** шлёт заголовок, и это стандартное поведение e-commerce («корзина следует за аккаунтом»). Правка сменила бы продуктовую семантику; при желании — отдельное решение.
- **Тач-таргет мест 22 px** (`SEAT_SIZE`). Увеличение размера ломает геометрию карты и редактора залов, а расширение зоны нажатия псевдоэлементом приведёт к перекрытию соседних мест (6 px зазор) и промахам на **чужое** место. Требует продуктового решения по мобильной раскладке, а не точечной правки.

## Регрессии

- `vue-tsc --noEmit` — чисто; `vite build` — успешно.
- `vitest run` — **55/55**.
- `php tests/run.php` — **636 методов, 0 падений, 1317 assertions** (9 Feature-файлов не запускаются этим раннером — как и до правок, нужен `vendor/`).
- E2E на стенде: холд 2 мест → `GET /cart` (корректные `row`/`sector`) → checkout → заказ создан, `total_amount` верный.

---

# Второй проход: ревизия качества кода (senior review)

Первый проход чинил то, что видно на экране. Второй — то, что видно только
читая код как единое целое: контракты между слоями, дублирование формул и
проверки, которые «есть, но не работают». Ниже — дефекты, найденные **только**
при сквозном чтении (на живом стенде они выглядят как «иногда сумма не сходится»
и «ошибка без объяснения»).

## Найденные дефекты

| # | Уровень | Дефект | Где | Суть |
|---|---------|--------|-----|------|
| **S1** | **P0** | Снятие места после F5 уходило на несуществующий id | `SeatSelectionPage.applyServerCart` | `cart.meta[id]` заполнялся **id инвентаря** вместо `cart_items.id`. После перезагрузки `DELETE /cart/items/{inventory_id}` → 404 `CART_ITEM_NOT_FOUND`: место оставалось удержанным, а покупатель считал, что снял его |
| **S2** | **P0** | Серверные ошибки валидации не доходили до полей | `lib/api.ts` | `request()` читал `error.errors`, сервер отдаёт `error.details.fields` → `ApiError.details` всегда `null`. `upload()` при этом читал правильный путь: **корневой причиной было дублирование парсинга** |
| **S3** | **P1** | Танцпол считался как один билет | `stores/cart.ts`, `OrderSummary.vue`, `CheckoutPage.vue` | Сводка использовала `seats.length` и `priceMinor` вместо суммы по `quantity`. Танцпол qty=3 → «1 билет» и цена одного билета, итог расходился с `orders.total_amount` |
| **S4** | **P1** | `quantity` терялся при восстановлении после F5 | `SeatSelectionPage.applyServerCart`, `CheckoutPage.restoreCart` | `cart.toggle()` вызывался без `quantity`, хотя `GET /cart` его отдаёт. Тот же эффект, что S3, но только после перезагрузки |
| **S5** | **P1** | Лимит 10 билетов на координатной карте не проверялся | `CoordSeatMap.vue` | Проп `maxQuantity` принимался, но не использовался; `@limit` объявлен, но **никогда не эмитился**. 11-е место уходило на сервер и возвращалось `422 VALIDATION_ERROR` вместо понятного сообщения |
| **S6** | **P1** | Повторный клик по танцполу не отпускал холд | `SeatSelectionPage.onToggleCoord`, `CoordSeatMap.toggleDance` | Ветка `standing` безусловно вызывала `holdSeat()`; локально выбор снимался, а серверный холд висел до истечения TTL |
| **S7** | **P2** | Танцпол нельзя было выбрать повторно | `CoordSeatMap.vue` | `danceSelected` — локальный `ref`, не связанный с пропсом `selected`: после удаления зоны из сводки она оставалась «выбранной» навсегда до перезагрузки |
| **S8** | **P2** | `ticketsCount` объявлен, но не экспортирован | `stores/cart.ts` | Значение было недостижимо для компонентов — «почти сделанная» правка |
| **S9** | **P2** | «Танцпол, место 0» в сводке и на чеке | `OrderSummary.vue`, `CheckoutPage.vue` | Стоячая зона приходит с `row=0, number=0`; шаблоны печатали «ряд 0, место 0» — место, которого не существует |
| **S10** | **P3** | Мёртвый код в заголовках запроса | `lib/api.ts` | `headers: options.body === undefined ? headers : headers` — тернарник с одинаковыми ветвями |
| **S11** | **P3** | Счётчик корзины в шапке считал позиции | `StorefrontShell.vue` | `aria-label="Корзина"` без числа; бейдж показывал позиции, а не билеты |

## Что сделано

| # | Правка | Файл |
|---|--------|------|
| S1 | `cart.meta[id] = String(item.id)` — `item.id` это `cart_items.id`, ровно то, что ждёт `DELETE` | `SeatSelectionPage.vue` |
| S2 | Вынесены `extractFieldErrors()` / `extractErrorMeta()`; **один** парсер для `request()` и `upload()`; канонический путь `error.details.fields`, плюс плоский `details` и legacy `error.errors` | `lib/api.ts` |
| S3, S4 | Введены чистые помощники `seatQuantity` / `seatLineTotal` / `seatsTicketCount` / `seatLineLabel` в сторе — единственное место, где считается количество; `ticketsCount` и `selectedSeats` как computed; `quantity` протянут через `applyServerCart` и `restoreCart` | `stores/cart.ts`, `SeatSelectionPage.vue`, `CheckoutPage.vue`, `OrderSummary.vue` |
| S5 | `CoordSeatMap` принимает `selectedCount` (билеты, не позиции) и проверяет лимит в `toggleSeat()` и `pickDance()`; пикер количества ограничен остатком зоны, остатком лимита и UI-максимумом | `CoordSeatMap.vue`, `SeatSelectionPage.vue` |
| S6 | Ветка `standing` различает выбор и снятие: снятие идёт через `releaseSeat()`, как у обычного места | `SeatSelectionPage.vue` |
| S7 | `danceSelected` — `computed` от `props.selected`; повторный клик эмитит снятие | `CoordSeatMap.vue` |
| S8 | `ticketsCount` и `selectedSeats` добавлены в возврат стора | `stores/cart.ts` |
| S9 | `seatLineLabel()`: «Партер, ряд 3, место 12» / «Танцпол · 3 билета»; бейдж позиции показывает `×N` для стоячей зоны | `stores/cart.ts`, `OrderSummary.vue`, `CheckoutPage.vue` |
| S10 | `headers` без мёртвого тернарника | `lib/api.ts` |
| S11 | Бейдж и `aria-label` считают билеты | `StorefrontShell.vue` |

## Закрепление тестами

Дефекты S1–S4 и S9 — это **инварианты**, а не разовые баги. Они закреплены тестами,
чтобы регрессия падала на CI, а не в проде:

| Файл | Тестов | Что стережёт |
|------|--------|--------------|
| `tests/Unit/Cart/cartStore.test.ts` | 12 | «позиция ≠ билет»: `count` vs `ticketsCount`, `subtotalMinor` с `quantity`, `hydrate` сохраняет количество, подпись стоячей зоны без «места 0», битый `quantity` не ломает сумму |
| `tests/Unit/Api/errorEnvelope.test.ts` | 6 | §66-конверт: `details.fields`, плоский `details`, legacy `errors`, код `CART_EXPIRED` при статусе 409, не-JSON ответ, **идентичный контракт у `upload()`** |

## Повторная проверка на живом стенде

Стенд: `artisan serve` на `127.0.0.1:8391`, MySQL в Docker (`nabilet-mysql`, порт 3307).
Запросы к localhost — с `--noproxy '*'` (иначе уходят в прокси и падают).

| Проверка | Результат |
|----------|-----------|
| `POST /cart/items` standing id=26, `quantity=3` | **201**, `data.id = 23` (`cart_items.id`), `quantity=3`, `total_price=240000` |
| `GET /cart?session_id=6` | `items[0].id = 23`, `items[0].quantity = 3`, `unit_price="80000"` — **именно те поля, которые теперь читает фронтенд** |
| `DELETE /cart/items/23` (**`cart_items.id`**) | **200** «Item removed from cart successfully» |
| `DELETE /cart/items/26` (**id инвентаря** — старая ошибка) | **404 `CART_ITEM_NOT_FOUND`** — воспроизведён дефект S1 |
| Остаток зоны 26 после снятия | `available_quantity = 20`, `status = available` — холд отпущен |
| `POST /cart/items` `quantity=11` | **422 VALIDATION_ERROR**, `details.fields.quantity = ["…не больше 10"]` — подтверждает и серверный лимит, и путь `details.fields` |
| `POST /cart/items` `quantity=10` | **201** — ровно лимит проходит |
| `GET /inventory` без `session_id` | **422** (ранее — 55 строк из 4 сеансов) |
| `GET /inventory?session_id=` (пустое) | **422** |

Тестовые данные вычищены: корзины 28/29 удалены (`seat_holds`, `cart_items`, `carts`),
остаток зоны 26 восстановлен до `20 / available`. Стенд остановлен.

## Итоговое состояние прогонов

| Проверка | Результат |
|----------|-----------|
| `vue-tsc --noEmit` | чисто |
| `vite build` | успешно, 10.22 s |
| `vitest run` | **73/73** (было 55, добавлено 18) |
| `php tests/run.php` | **636 методов, 0 падений, 1317 assertions** |

## Остаточные ограничения (честно)

- **Скриншоты и ручная проверка в браузере не выполнялись** — в среде нет запущенного
  браузера. Правки S5–S7 (лимит, снятие танцпола, повторный выбор) проверены
  тестами и чтением кода, но не кликами по живому интерфейсу. Для полной
  уверенности нужен прогон на стенде с браузером.
- **`NABILET_HOLD_TTL=600`** (сборщик мусора `seat_holds`) и
  **`CHECKOUT_HOLD_DURATION=15` мин** (`carts.expires_at`) — это **две разные
  величины**. UI живёт по `carts.expires_at` (15 мин), поэтому `NCountdown
  :total="900"` корректен. Локальный фолбэк `HOLD_SECONDS = 600` в сторе
  срабатывает только если сервер не прислал `expires_at` — расхождение
  безвредно, но стоит помнить о нём при чтении кода.

---

# Третий проход: «мёртвые ручки» конфигурации

Отдельный класс дефектов, который не видно ни на экране, ни в тестах: **настройка
объявлена, задокументирована и не читается никем.** Оператор меняет значение,
получает «сохранено» и не получает никакого эффекта. Найдено четыре таких ручки,
и одна из них — заявленный «anti-scalping guard».

## Найденные дефекты

| # | Уровень | Дефект | Доказательство |
|---|---------|--------|----------------|
| **C1** | **P1** | `NABILET_MAX_SEATS_PER_ORDER=8` («Max seats per order — anti-scalping guard») не читает **ни один** `env()`. Лимит фактически равен 10 — литералу `max:10` в `CartController::addItem` | `grep -rn "NABILET_MAX_SEATS_PER_ORDER"` → только `.env`, `.env.example` и докблок. `quantity=10` → **201**, затем checkout → **200** |
| **C2** | **P1** | `nabilet.checkout.max_items_per_order` (`CHECKOUT_MAX_ITEMS`) — ключ конфига не читался вовсе | `grep -rn "max_items_per_order"` → единственное вхождение — само объявление в `config/nabilet.php:50` |
| **C3** | **P1** | Лимит на клиенте — жёсткая константа `MAX_TICKETS_PER_ORDER = 10`, дублирующая сервер | Снижение лимита на сервере не меняло поведение UI: кнопка нажимается, запрос отвергается 422-м |
| **C4** | **P2** | `NABILET_HOLD_TTL` задокументирован как «How long a selected seat stays reserved before payment» — **неправда**. Читается только в `config/queue.php` как `retry_after` очереди | `grep -rn "NABILET_HOLD_TTL"` → `config/queue.php:21,29`. Реальный срок — `seat_holds.expires_at` из `CHECKOUT_HOLD_DURATION` (15 мин) |
| **C5** | **P2** | `NABILET_HOLD_GRACE=30` не читается никем; фактический grace захардкожен как 5 минут в `HoldSweeper` и `PaymentService` | `grep -rn "NABILET_HOLD_GRACE"` → `.env`, `.env.example`, докблок. В коде — `subMinutes(5)` в двух местах |
| **C6** | **P2** | `HoldWindow` (домен) заявляет TTL 10 мин и grace 30 с, но в проде не используется; `OrderPlacement` — его единственный потребитель — не создаётся нигде | `grep -rn "new OrderPlacement" app/` → пусто. `HoldWindow` встречается только в тестах и в докблоках |
| **C7** | **P3** | Тач-таргет места 22 px — ниже минимума WCAG 2.2 SC 2.5.8 (24×24) | `hall.ts:35` `SEAT_SIZE = 22` |

## Что сделано

| # | Правка | Файл |
|---|--------|------|
| C1, C2, C3 | **Один источник лимита.** `CartController::addItem()` читает `config('nabilet.checkout.max_items_per_order')` вместо литерала `max:10`; сервер отдаёт число витрине в `meta.max_tickets_per_order`; клиент берёт его оттуда, а `MAX_TICKETS_PER_ORDER` остаётся только фолбэком для старой сборки API | `CartController.php`, `InventoryController.php`, `lib/inventory.ts`, `SeatSelectionPage.vue` |
| C4, C5 | Комментарии приведены к факту: `.env.example` больше не называет `NABILET_HOLD_TTL` сроком удержания; добавлены отсутствовавшие `CHECKOUT_HOLD_DURATION` и `CHECKOUT_MAX_ITEMS`; мёртвые переменные закомментированы с пояснением, что именно их заменило | `.env.example`, `config/queue.php`, `routes/console.php`, `ClearExpiredHoldsCommand.php` |
| C6 | Докблоки `HoldWindow` и `ReservationPolicy` больше не описывают несуществующее поведение: добавлены таблицы «как заявлено / как в проде» и явное «в боевом пути не участвует» | `HoldWindow.php`, `ReservationPolicy.php` |
| C7 | Зона нажатия расширена до 24×24 через `::after { inset: -1px }` в **scoped**-стиле `SeatMap.vue`. Визуальный размер не меняется; перекрытие исключено арифметически (шаг 28 px, зона 24 px → 4 px зазор). В `app.css` не вынесено: класс `.seat` использует и `HallEditor.vue` | `SeatMap.vue` |

## Решающая проверка C1–C3 на живом стенде

Правка бессмысленна, если ручка по-прежнему мертва. Поэтому проверялось не
чтение кода, а **изменение поведения при изменении настройки**:

| Шаг | Ожидание | Результат |
|-----|----------|-----------|
| `CHECKOUT_MAX_ITEMS` не задан → `GET /inventory` | `meta.max_tickets_per_order = 10` | **10** |
| Временно `CHECKOUT_MAX_ITEMS=3` + перезапуск → `GET /inventory` | `meta = 3` | **3** |
| `POST /cart/items` `quantity=3` | 201 (ровно лимит) | **201** |
| `POST /cart/items` `quantity=4` | 422, текст «не больше 3» | **422**, `details.fields.quantity = ["…должно быть не больше 3."]` |
| `.env` возвращён, перезапуск → `GET /inventory` | `meta = 10` | **10** |

До правки изменение `CHECKOUT_MAX_ITEMS` не влияло ни на что: значение в `.env`
было 8, система пропускала 10, и оба числа были неверны относительно друг друга.

## Что НЕ менялось — и почему

- **Значение лимита оставлено 10.** Это текущее фактическое поведение, и менять
  его — продуктовое решение, а не исправление. Раньше оператор не мог выбрать
  вообще: настройка не работала. Теперь достаточно `CHECKOUT_MAX_ITEMS=8` в `.env`.
- **`ReservationPolicy`/`OrderPlacement` не включены в боевой путь.** Это была бы
  смена архитектуры оформления заказа. Докблок теперь честно говорит, что класс
  не задействован, и что для его включения нужно создать `OrderPlacement` в
  сервисе оформления и передать лимит из конфига.
- **`NABILET_HOLD_GRACE` не подключена к коду.** Подключение означало бы смену
  grace-периода с 5 минут на 30 секунд — сокращение окна оплаты в 10 раз. Это
  продуктовое решение: grace защищает покупателя, который платит на последней
  секунде.
- **`.env` не вычищен.** В нём остались мёртвые `NABILET_HOLD_TTL=600`,
  `NABILET_HOLD_GRACE=30`, `NABILET_MAX_SEATS_PER_ORDER=8` (файл в `.gitignore`,
  это локальная конфигурация). Они безвредны, но `.env.example` теперь
  предупреждает о каждой.

## Проверки

| Проверка | Результат |
|----------|-----------|
| `vitest run` | **79/79** (было 73, добавлено 6 на контракт лимита) |
| `php tests/run.php` | **636 методов, 0 падений, 1317 assertions** |
| `vue-tsc --noEmit` | чисто |
| `vite build` | успешно, 10.45 s |
| `php -l` по всем правленым PHP-файлам | без ошибок |

Тестовые данные вычищены: корзины 30/31, заказ 11 и его `order_items` удалены,
остаток зоны 26 восстановлен до `20 / available`, `.env` возвращён в исходное
состояние, сервер остановлен.

---

# Четвёртый проход: единый источник grace-окна и правда о тестовом раннере

Задача прохода: закрыть остаток «мёртвых ручек» и ответить на вопрос «что нужно
рефакторить». По ходу работы выяснилось, что часть тестовой инфраструктуры
описывает себя неверно — и это скрывало настоящий дефект.

## Найденные дефекты

**D1. Grace-окно холда продублировано пятью литералами.** Число «сколько ещё
секунд после `expires_at` платёж считается валидным» было зашито как
`addMinutes(5)` / `subMinutes(5)` в пяти местах трёх модулей:

```
app/Modules/Inventory/Services/HoldSweeper.php:56   ->where('expires_at', '<', $now->subMinutes(5)…)
app/Modules/Inventory/Services/HoldSweeper.php:176  $deadline = …->subMinutes(5)…
app/Modules/Inventory/Services/HoldSweeper.php:283  $gracePeriod = $expiresAt->addMinutes(5)
app/Modules/Orders/Models/SeatHold.php:93           $this->expires_at->copy()->addMinutes(5)
app/Modules/Payments/Services/PaymentService.php:450 …->addMinutes(5)
```

Копии ничем не связаны. Расхождение — не косметика: `sweep()` вернул бы место в
продажу, пока `isHoldConvertible()` ещё считает холд живым, и вебхук оплаты упал
бы в «hold already released». Покупатель платит и теряет место.

**D2. Тестовый раннер утверждал, что `vendor/` отсутствует.** `tests/run.php`
печатал на каждом прогоне: «`vendor/` (they drive the framework), which is absent
here, so they have never been executed». **Проверено — это ложь**:

```
vendor/bin/phpunit   → PHPUnit 11.5.56
vendor/bin/phpstan   → PHPStan 1.12.34
```

То есть 9 файлов `tests/Feature/*` не «не могли» запускаться — их просто никто не
запускал, а раннер давал этому правдоподобное объяснение.

**D3. Устаревший тест на отмену платежа (следствие D2).** После того как набор
запустили, `PaymentWritePathTest` дал 1 падение из 62:

```
Tests\Feature\Payments\PaymentWritePathTest::test_webhook_failed_stores_the_reason_in_metadata_json
Failed asserting that two strings are identical.
--- Expected  'failed'
+++ Actual    'canceled'
```

Причина: тест отправлял `payment.canceled`, но ожидал `failed`. Так было верно **до
A12**, когда оба события шли в один обработчик. После разделения
(`handlePaymentCanceled`, `PaymentStateMachine::CANCELED`) контракт изменился, а
тест остался прежним — и оставался незамеченным ровно потому, что набор не
запускался. Проверено, что права реализация, а не тест: `handlePaymentCanceled`
имеет явный докблок A12, `CANCELED` и `FAILED` — разные терминальные состояния
машины (`PaymentStateMachine.php:51-52,63-64,75-76`), маршрутизация вебхука
разделяет события (`PaymentService.php:300-306`).

**D4. Ослабленная проверка из-за интерполяции строки.**
`AuthWiringTest.php:134` писал иглу в двойных кавычках:
`"$app->refresh('request'"`. PHP интерполирует `$app->refresh` как **свойство**
несуществующей переменной. Доказано исполнением:

```
PHP Warning: Undefined variable $app
PHP Warning: Attempt to read property "refresh" on null
длина иглы: 10
игла: [('request']
```

Игла выродилась в `('request'` вместо `$app->refresh('request'` — тест прошёл бы,
даже если бы провайдер вызывал `->refresh(` на чём угодно.

**D5. PHPStan анализирует Laravel без знания фреймворка.** `phpstan.neon`
объявляет `level: 5` и пути `app/config/routes/database/tests`, но **не подключает
ни одного расширения**: `includes` отсутствует, `larastan/larastan` нет ни в
`composer.json`, ни в `vendor/`. Результат — 614 сообщений, из которых
подавляющее большинство артефакты отсутствия Larastan (378 «undefined property»
на колонках моделей, 77 «undefined method» на Eloquent-магии, 20 «on stdClass»,
20 «should return … but returns»).

**D6. Объявленный контракт ≠ фактический.** `OrganizationRepository::all()` и
`OrganizationService::all()` объявлены как `Collection`, а возвращают
`LengthAwarePaginator` (`paginate($limit)`). Работало случайно:
`ResourceCollection::toResponse()` распознаёт `AbstractPaginator`.

## Что сделано

| Правка | Файлы |
|---|---|
| Grace-окно → один ключ конфига | `config/nabilet.php` (+`hold_grace_minutes`), `HoldSweeper.php`, `SeatHold.php`, `PaymentService.php` |
| Страховочный тест на единый источник | `tests/Unit/HoldGraceTest.php` (новый, 4 метода) |
| Правда о раннере + `--strict` запускает PHPUnit | `tests/run.php` |
| Устаревший тест приведён к контракту A12 + новый тест ветки отмены | `tests/Feature/Payments/PaymentWritePathTest.php` |
| Экранирование иглы | `tests/Unit/AuthWiringTest.php` |
| PHPStan: исключён архив, убрана устаревшая опция | `phpstan.neon` |
| Типы приведены к факту | `OrganizationRepository.php`, `OrganizationService.php` |
| Документация | `docs/CODE-QUALITY-GUIDE.md` (§3.2, §7, §8, новый §9), `HoldWindow.php`, `.env.example`, `.env` |

## Решающие проверки

**Grace-окно — ручка живая, доказано сменой значения.** Пробник поднимал
приложение и вызывал `SeatHold::graceMinutes()`:

```
CHECKOUT_HOLD_GRACE_MINUTES=5   → 5  → граница релиза для expires_at 12:00:00 = 12:05:00
CHECKOUT_HOLD_GRACE_MINUTES=17  → 17 → граница релиза для expires_at 12:00:00 = 12:17:00
```

После проверки значение возвращено на `5`, пробник удалён.

**Страховочный тест не пустой.** Инвариант ломался намеренно (ключ конфига
переименован в `hold_grace_minutes_BROKEN`), конфиг восстановлен из резервной
копии:

```
FAIL  HoldGraceTest   3 passed   1 failed   20 assertions
  → testConfigDeclaresTheSingleSource — Ключ конфига grace-окна исчез или переименован.
```

**`--strict` действительно гейт.** Временный заведомо падающий Feature-тест:
`EXIT=1`. После удаления — `EXIT=0`.

**«Ложное срабатывание» доказано исполнением, а не рассуждением.** Две гипотезы
были проверены запуском и **опровергнуты**:

- `Call to protected method increment()/decrement()` (4) — `Model::increment()`
  действительно `protected` (`Model.php:1094`), но `Model::__call()` явно мостит
  эти имена (`Model.php:2857`), поэтому вызов работает. Атомарное списание
  инвентаря не трогал.
- `Call to static method table()/error()/call() on an unknown class DB/Log/Artisan`
  (11) — алиасы регистрируются не в `config/app.php` (ключа `aliases` там нет), а
  слиянием фреймворкового `vendor/laravel/framework/config/app.php`
  (`'aliases' => Facade::defaultAliases()`). Проверено:
  `class_exists('DB') === true`, `\DB::table('carts')->count()` вернул `11`.

## Проверки

| Проверка | Результат |
|----------|-----------|
| `php tests/run.php --strict` | **640 unit + 63 Feature, 0 падений, 1337 + 234 assertions** |
| `vendor/bin/phpstan analyse` | 614 сообщений (было 621; −6 архивных, −1 исправленный), Larastan не установлен — см. §9.1 |
| `vue-tsc --noEmit` | чисто |
| `vitest run` | **79/79** |
| `vite build` | успешно, 10.06 s |
| `php -l` по всем правленым PHP-файлам | без ошибок |

Временные файлы удалены (`probe-*.php`, `config/nabilet.php.bak`, промежуточные
дампы PHPStan). `outputs/seat-audit/phpstan-errors.txt` — 614 реальных сообщений
как исходные данные для §9.1. `.env` возвращён в согласованное с `.env.example`
состояние (`CHECKOUT_HOLD_GRACE_MINUTES=5` вместо неработавшей
`NABILET_HOLD_GRACE=30`).

---

# Пятый проход: закрытие P1 и P2 из §9 гайда

Задача: устранить пункты P1 (9.8, 9.9) и P2 (таблица «Размер и связность»).
Пять файлов из таблицы P2 разделены; P1 закрыт переносом и удалением. Ниже —
что именно изменилось, и отдельно — что разделение **нашло**, потому что
нашло оно два боевых дефекта.

## 1. P1.9.8 — орфан `components/HallEditor.vue`

Удалены `resources/js/components/HallEditor.vue`, точка входа
`resources/js/app/hall-editor.js` и тест `tests/Unit/Components/HallEditor.test.js`
(417 строк, 31 из 79 тестов vitest защищали код, который никто не импортирует).

**Побочный эффект, которого не было видно по импортам.** Сборка сломалась:
`npx vite build` → `Could not resolve entry module 'resources/js/app/hall-editor.js'`.
Причина — `vite.config.js` (легаси-конфиг) всё ещё перечислял удалённый файл в
`build.rollupOptions.input`. Удалённый модуль упоминался **строкой в конфиге
сборки**, а не импортом, поэтому `vue-tsc` и vitest молчали. Урок записан в
§9.8: удаляя модуль, надо грепать конфиги сборки, а не только импорты.

Сам `vite.config.js` **не удалён** намеренно: это серверный легаси-конфиг
(`outDir: public_html/build`), его подхватывает голый `vite build`; удаление —
отдельное решение, требующее проверки на сервере.

## 2. P1.9.9 — мёртвый доменный кластер

`OrderPlacement`, `ReservationPolicy`, `HoldWindow`, `CartPolicy`, `CartCheckout`,
`CartState`, `CheckoutLine`, `CheckoutVerdict`, `InventoryStock`,
`Cart\Domain\{Cart,CartItem,CartDecision}`, `Inventory\Domain\SeatHold` перенесены
в `Superseded/` внутри своих модулей, каждый каталог — со своим `README.md`.

Кластер **не удалён**, и это осознанно: он покрыт 38 тестами
(`tests/Unit/CartTest.php` и соседи), которые проверяют формулы, нужные при
переводе оформления заказа на доменную модель. Цель переноса — не «убрать
мёртвое», а снять ловушку: раньше `app/Modules/Cart/Domain/CartPolicy.php`
выглядел как действующее правило оформления. Теперь об этом говорит сам путь.

## 3. P2 — разделение пяти файлов

| Файл | Было | Стало | Что вынесено | Тесты |
|---|---:|---:|---|---|
| `Inventory/Services/HoldSweeper.php` | 350 | **224** | `Core/Support/HoldGrace.php` (95), `Inventory/Services/SeatHoldLifecycle.php` (100), `Orders/Services/StaleOrderExpirer.php` (160) | `HoldGraceTest` усилен: «ключ конфига читается ровно в одном файле» |
| `Cart/Services/CartService.php` | 627 | **146** | `Cart/Services/CartItemService.php` (434), `Cart/Services/CartCheckoutService.php` (214) | `CartWritePathTest` — **8 новых** |
| `components/seat/CoordSeatMap.vue` | 521 | **527** | `lib/seatViewport.ts` (132) | `seatViewport.test.ts` — **14 новых** |
| `pages/storefront/SeatSelectionPage.vue` | 706 | **250** | `useSeatInventory.ts` (214), `useSeatHolds.ts` (375) | — |
| `pages/hall-editor/HallEditorPage.vue` | 3039 | **2566** | `hallEditorFields.ts` (95) | `hallEditorFields.test.ts` — **23 новых** |

Строк в `CoordSeatMap.vue` не убавилось: геометрия была компактной, а замена
inline-вычисляемых на импорт добавила строк. Выигрыш не в строках, а в
покрытии — 14 тестов вместо нуля. Это стоит помнить, читая любую таблицу
«до/после» по размеру файлов.

## 4. Что разделение нашло: два боевых дефекта

Оба в `CartService` → `CartCheckoutService`/`CartItemService`. Оба
воспроизведены **до** правки и падали на новом тесте. Оба жили потому, что
write-путь корзины не имел ни одного теста: ни один Feature-тест не вызывал
`/api/v1/cart/*`, а `tests/Unit/CartTest.php` покрывает архивный кластер
`Cart\Domain\Superseded\*`.

### 4.1. Протухшая корзина на `checkout()` не возвращала места

`CartCheckoutService::checkout()` освобождал инвентарь и выбрасывал
`ConflictError::cartExpired()` **внутри** `DB::transaction()`. Транзакция
откатывалась на throw — вместе с освобождением и переводом корзины в
`abandoned`. Ветка была недостижима по результату, хотя комментарий в коде
утверждал «освобождает инвентарь немедленно».

Измерено дампом состояния сразу после ответа `409 CART_EXPIRED`:
`carts.status` = `active` (не `abandoned`), у строки `seat_holds.released_at` =
`null`, `inventory_items.available_quantity` занижен — места списаны, но не
возвращены.

Исправление: локальный флаг `$expired`, `return null` из замыкания, а
`throw ConflictError::cartExpired()` — **после** коммита транзакции.

### 4.2. Повторный `addItem()` не увеличивал холд — места утекали навсегда

`seat_holds` создавался только при **первой** вставке позиции корзины. При
добавлении к существующей позиции код уменьшал
`inventory_items.available_quantity` и увеличивал `cart_items.quantity`, но
холд не трогал. Инвариант «сумма `seat_holds.quantity` по активным холдам
равна `cart_items.quantity`» нарушался. Так как **все** пути освобождения
(`releaseCartInventory()`, `HoldSweeper`, `StaleOrderExpirer`, очистка при
протухшем checkout) возвращают места строго по `seat_holds.quantity`, лишняя
единица не возвращалась никогда.

Сквозной замер: вместимость 5, два добавления, ожидание истечения холда →
`available_quantity` = **3 вместо 5**.

Исправление: `growHold()`, вызываемый из ветки существующей позиции —
`lockForUpdate()` по активному холду пары (cart, inventory_item), при
отсутствии — создание.

### 4.3. Побочный дефект: `isConvertible()` описывал не то правило

`Orders\Models\SeatHold::isConvertible()` содержал свою версию grace-окна
(`isFuture() || now()->lt(...)`) — то есть вторую реализацию правила, которое
`HoldGrace` теперь единолично владеет. Сведён к `HoldGrace::isWithinGrace()`.
Метод **не имеет ни одного вызова** — это зафиксировано в докблоке, чтобы
следующий читатель не искал, где он применяется.

## 5. Закрепление тестами

| Файл | Тестов | Что защищает |
|---|---:|---|
| `tests/Feature/Cart/CartWritePathTest.php` | 8 | резервирование, **повторное добавление**, протухший checkout, чужая сессия, снятие позиции, продление холда, оформление, пустая корзина |
| `tests/Unit/HallEditor/hallEditorFields.test.ts` | 23 | цена → копейки без нуля, пустой ввод как снятие переопределения, границы полей, отрицательный угол |
| `tests/Unit/Seat/seatViewport.test.ts` | 14 | вписывание в бокс, приоритет меньшего масштаба, `row1.y < row2.y` (Y не инвертирован), регресс пустого зала |
| `tests/Unit/HoldGraceTest.php` | 7 | ключ конфига читается ровно в одном файле `app/**`; ни один потребитель не читает его напрямую |

Один тест был **моей** ошибкой, а не дефектом продукта: проверка продления
холда падала, потому что оба действия попадали в одну секунду, а запись через
Eloquent обрезает время до секунд. Исправлено `travel(5)->minutes()` — то есть
тест, а не код.

## 6. Проверки

| Проверка | Результат |
|----------|-----------|
| `php tests/run.php` | **643 passed, 0 failed, 1361 assertions** |
| `php vendor/bin/phpunit` | **OK (96 tests, 386 assertions)** — на входе 63 |
| `php tools/verify-error-envelope.php` | 395 файлов, **OK** |
| `npx vue-tsc --noEmit` | EXIT=0 |
| `npx vitest run` | **122 passed / 9 файлов** (на входе 79 / 7) |
| `npm run build` (`--config vite.config.ts`) | успешно, 8.25 s |
| `npx vite build` (голый, легаси-конфиг) | успешно — регрессия из §1 закрыта |

**Числа нельзя читать как «прирост от рефакторинга».** В дереве одновременно
работает вторая сессия, и часть тестовых файлов создана ею, а не этим проходом:

- PHPUnit 63 → 96: на этот проход приходится 8 (`CartWritePathTest`), остальное —
  `HallTableGeometryTest`, `InventoryAvailabilityCountingTest`,
  `HallSchemaPayloadContractTest`, `HallSchemaSingleLiveVersionTest` и соседи.
- vitest 79 → 122: на этот проход приходится 37 (23 `hallEditorFields` +
  14 `seatViewport`); `tests/Unit/HallEditor/danceZone.test.ts` (9 тестов) добавлен
  второй сессией.

**Конкурентная запись затронула файл из этого прохода.** `CoordSeatMap.vue` был
изменён вторым процессом уже после моего выноса (527 → 562 строки). Проверено
постфактум: вынос уцелел — `import { fitPoints, projectPoint } from '@/lib/seatViewport'`
на месте, `seatViewport.ts` не тронут, `vue-tsc` и `vitest` зелёные, и в докблоках
файла теперь есть ссылка на вынесенный модуль. То есть чужая правка надстроилась
над этим рефакторингом, а не откатила его.

### 6.1. Ложная тревога: PHPUnit красный из-за чужого `migrate:fresh`

Прогон в 22:24 дал **75 ошибок** (`Table 'nabilet_test_suite.events' doesn't exist`,
`payments doesn't exist`), и это выглядело как регрессия от рефакторинга. Регрессией
не было. Как это установлено:

1. Изоляция: `phpunit --filter OrderWritePathTest` → **OK (16 tests)**, хотя в общем
   прогоне именно этот файл падал первым.
2. Характер ошибок: «таблицы не существует» — уровень схемы, а не логики.
3. **Недетерминированность:** два одинаковых прогона подряд дали 75 ошибок, затем
   17 ошибок + 1 failure. Детерминированный дефект так себя не ведёт.
4. Прямая улика: `Get-CimInstance Win32_Process` показал процесс
   **`artisan migrate:fresh --force` (PID 40812, старт 22:23:23)** — он дропал схему
   ровно во время прогона. `RefreshDatabase` пересоздаёт `nabilet_test_suite` целиком,
   поэтому две параллельные операции над ней несовместимы.
5. После завершения чужого процесса — **OK (96 tests, 386 assertions)**.

Правило: **красный PHPUnit в этом дереве сначала проверяется на параллельный
`migrate:fresh`, и только потом на свой код.** Признак именно контенции, а не
дефекта, — меняющееся от прогона к прогону число ошибок при неизменном коде.
Это тот же класс, что описан в `MEMORY.md` («никогда не поднимать dev-сервер на
`nabilet_test_suite`»), только с другой стороны: не сервер мешает тестам, а
миграция.

## 7. Остаточные ограничения (честно)

- **Канвас-слой `HallEditorPage.vue` (~600 строк) не вынесен.** Это измерение,
  а не оценка: `stage` и пять слоёв Konva упоминаются вне блока холста
  **33 раза** — 28 в блоке инструментов создания, 4 в блоке рамки выделения и
  автосохранения, 1 в горячих клавишах. Холст — не отделимый модуль, а общее
  состояние четырёх блоков. Предварительное условие — component-test harness с
  `vi.mock('konva')`: в jsdom Konva падает без `getContext('2d')`. Вынос до
  появления тестов = 600 строк перемещений без проверок.
- **PHPStan не гейт.** 644 сообщения против 614 на входе; вклад этого прохода
  **+8**, и это тот же класс артефактов, переехавший вместе с кодом
  (`CartService` 29 → 3, зато `CartItemService` +25 и `CartCheckoutService` +7).
  Остальные +18 дали файлы вне этого прохода. Без Larastan число ошибок нельзя
  использовать как метрику прогресса.
- **Проверка кликами не выполнялась.** Браузера в среде нет. Всё, что
  проверено, проверено тестами и прогоном на стенде; визуальная часть
  `CoordSeatMap`/`SeatSelectionPage` после разделения не открывалась в
  браузере, и это единственный способ убедиться, что перестановка импортов не
  поменяла порядок отрисовки.
- **CI.** В job `verify` добавлен отсутствовавший ратчет
  `verify-error-envelope.php`. Фронтенд-гейты (`npm run typecheck`, `npm test`,
  `npm run build`) и PHPUnit в CI по-прежнему не подключены; готовый YAML —
  в §P3 гайда, но первый прогон должен быть сделан руками, потому что `npm ci`
  в этой среде не проверялся.
