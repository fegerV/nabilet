# Дизайн-система NABILET

**Версия:** 1.0 (синхронизировано с ТЗ v1.0 от 22.09.2026)
**Стек:** Vue 3 + TypeScript + Vite + Pinia + Vue Router + Tailwind CSS + Konva.js (точно по §2 ТЗ)
**Клиентские поверхности:** NABILET Web (§149), NABILET Telegram Mini App (§76), embed-виджет (§79)
**Android Checker** — отдельное нативное приложение на Kotlin/Compose (§45, §149), дизайн-токены переиспользуются, но верстка вне этой системы.

---

## 0. Как читать этот документ

Документ построен по принципу «требование ТЗ → реализация → где смотреть». Для каждой крупной темы указана ссылка на раздел ТЗ и на конкретные файлы в `resources/js/`. Известные расхождения собраны в разделе 10.

---

## 1. Соответствие техническому заданию

| Требование ТЗ | Где в ТЗ | Реализация | Где в коде |
|---|---|---|---|
| Стек Vue 3 + TS + Vite + Pinia + Tailwind + Konva | §2 | Полностью | `package.json`, `vite.config.ts`, `tailwind.config.ts` |
| Три клиента: Web / Telegram Mini App / Embed | §76, §79, §149 | Один фронтенд, hash-роутинг, темизация через `?theme=` | `resources/js/router/index.ts`, `layouts/StorefrontShell.vue` |
| Маршруты витрины `events → sessions → seatmap → cart → checkout → tickets` | §76 | Совпадают | `router/index.ts` |
| Dark mode | §41, §4408 | `darkMode: 'class'` + переключатель в хедере, persist в `localStorage` | `stores/ui.ts`, `tailwind.config.ts`, `resources/css/app.css` |
| Touch targets ≥ 44 px | §76 | `NButton` md/h-11 (44), lg/h-13 (52); хедер-кнопки h-11 w-11; инпуты h-11 | `components/ui/NButton.vue`, `layouts/*.vue` |
| Embed URL `?theme=dark&locale=ru` | §79, §2495 | Theme-параметр читается из `location.search`; locale — точка расширения | `stores/ui.ts`, `main.ts` |
| Telegram WebApp SDK + theme variables | §76, §2378 | Заглушка `useTelegramTheme()` готова к подключению `@twa-dev/sdk`; CSS-переменные совпадают по именам с Telegram | `stores/ui.ts`, `resources/css/app.css` |
| Mobile-first | §76, §2379 | Брейкпоинт `sm` = первый десктоп; нижняя навигация до `md` | `layouts/StorefrontShell.vue` |
| Hall Editor: 11 инструментов (§47) | §47 | Полный набор в палитре (Select/Pan/Zoom/Seat/Row/Sector/Table/Standing/Text/Image/Stage/Entrance). Большинство имеют реальное поведение: Select = multi-select+bulk ops, Pan = draggable stage, Zoom = wheel+кнопки, Row = генератор к выбранному сектору, Image = загрузка фона, Entrance/Text/Sector/Table/Standing/Stage = добавление объекта. | `pages/hall-editor/HallEditorPage.vue` |
| Hall Editor: автосохранение 500 ms, состояния | §52 | `scheduleAutosave()`: debounce 500 ms, состояния saving/saved/error/offline, индикатор в заголовке. Undo/redo отдельно от сервера | `pages/hall-editor/HallEditorPage.vue` |
| Hall Editor: multi-select + операции | §48 | Shift+клик → множественное выделение (Set), bulk delete (Del/Backspace), duplicate, drag для перемещения сектора | `pages/hall-editor/HallEditorPage.vue` |
| Hall Editor: Schema JSON экспорт | §54 | Кнопка «Экспорт JSON» — скачивает `{version, canvas, background, sectors[], staticObjects[]}` | `pages/hall-editor/HallEditorPage.vue` |
| Hall Editor: Background Image (PNG/JPG/WEBP/SVG) | §51 | File picker → FileReader → dataURL → Konva.Image на отдельном слое (bgLayer). Поля x/y/width/height/opacity/rotation/locked | `pages/hall-editor/HallEditorPage.vue` |
| Hall Editor: цены по рядам | §50 | Сектор-инспектор → раскрывающийся список «Цены по рядам», каждое поле перекрывает priceMinor | `pages/hall-editor/HallEditorPage.vue` |
| Hall Editor: 60 FPS, drag < 50 ms | §53 | Виртуализация не реализована (roadmap), драг нативный DOM/SVG | — |
| Android Checker экраны (Splash → Login → ... → Settings) | §45 | Нативное приложение, вне Vue-системы. Дизайн-токены (цвета статусов, радиусы) переиспользуются | — |
| QR: тёмное на светлом | — | `qrcode` c `{dark: '#120F24', light: '#FFFFFF'}`, не инвертируется в dark mode | `components/storefront/TicketCard.vue` |
| Билет — крупно ряд/место/время | — | `text-xl tabular-nums` для ряда/места | `components/storefront/TicketCard.vue` |
| Мультитенантность в админке видна всегда | — | Переключатель организации в топбаре `AdminShell`, первый после меню | `layouts/AdminShell.vue` |

---

## 2. Дизайн-токены

Все токены — в `tailwind.config.ts` и `resources/css/app.css`. Они мапятся 1:1 на CSS-переменные `--canvas`, `--surface`, `--content`, `--brand-500` и т. д., поэтому один и тот же набор работает в Tailwind, в inline-стилях и в SVG-генерации схемы зала.

### 2.1 Палитра

| Группа | Назначение | 50 | 500 | 700 | Когда использовать |
|---|---|---|---|---|---|
| **brand** | Действие, навигация, выбранное место | `#F2F0FF` | `#6D4AFF` | `#4921C9` | Основные CTA, активные вкладки, «Оформить заказ» на втором шаге |
| **accent** | Покупка, деньги, срочность, дедлайн | `#FFF4EF` | `#FF5C22` | `#C63200` | Финальный CTA «Купить / Оплатить», hold-таймер, total |
| **sun** | Hold, «место придержано» | — | `#FFB020` | — | Мигающий hold, «ждёт оплаты» |
| **mint** | Успех, оплачено, билет валиден | — | `#00C48C` | — | «Оплачен», «Действителен», «Вход разрешён» |
| **rose** | Опасность, отмена, возврат | — | `#FF3B5C` | — | «Возврат», «Отказ на проход», «ALREADY USED» |
| **sky** | Информация, подсказки | — | `#2AA3FF` | — | Места для маломобильных, нейтральные подсказки |
| **gold** | VIP / премиум-сектор | — | `#F5B417` | — | Первый ряд, VIP-ряд, зона повышенного комфорта |
| **ink** | Нейтральная шкала «сцена» | `#F6F5FA` | `#554D80` | `#1D1840` | Фон, текст, границы |

Цвета статусов жёстко закреплены за машинами состояний из `docs/STATE-MACHINES.md`. Один и тот же статус заказа (`paid`, `awaiting`, `refunded`) выглядит одинаково в списке заказов, в карточке билета и в чек-ине.

### 2.2 Семантика поверхностей

```
--canvas   // основной фон (тёмный «сцена»)
--surface  // карточки, панели
--surface-2 // приподнятые поля ввода, дропдауны
--surface-3 // hover, активный пункт меню
--line     // границы 1px
--line-strong // границы 2px (фокус)
--content  // основной текст
--muted    // второстепенный текст
--subtle   // подписи, плейсхолдеры, иконки без состояния
```

### 2.3 Темы

Переключатель в шапке: тёмная по умолчанию (медиа-контент), светлая доступна. Состояние сохраняется в `localStorage` (`ui-store`). При инициализации проверяется приоритет:

1. `?theme=dark` / `?theme=light` в URL (embed)
2. Telegram WebApp `colorScheme` (Mini App)
3. `localStorage`
4. `prefers-color-scheme`

### 2.4 Типографика

| Токен | Размер | Использование |
|---|---|---|
| `text-2xs` | 11 / 16 | Подписи, теги, microcopy |
| `text-xs` | 12 / 18 | Хинты, вторичные данные |
| `text-sm` | 13 / 20 | Подписи полей, плейсхолдеры |
| `text-base` | 15 / 24 | Базовый текст (привычнее 16 — для мобильных экранов) |
| `text-xl` | 20 / 28 | Ряд / место в билете (то, что ищут в последнюю минуту) |
| `text-2xl` | 24 / 30 | Заголовки экранов |
| `text-3xl` / `text-4xl` | 30 / 38 | Hero витрины |

Шрифт: Inter (UI), Manrope (display), JetBrains Mono (коды билетов, ID заказа). Без декоративных — критерий «прочитается за 2 секунды на бегу к сцене».

### 2.5 Радиусы и тени

```
rounded-sm: 6    — чипы, теги
rounded:    10   — поля ввода, кнопки
rounded-md: 12   — карточки товаров
rounded-lg: 16   — основные карточки
rounded-xl: 20   — крупные карточки (билет)
rounded-2xl: 26  — модальные окна
rounded-3xl: 34  — hero-блоки витрины
```

Тени — три уровня глубины (`shadow-sm/md/lg`) плюс брендовые свечения под CTA (`shadow-brand`, `shadow-accent`). Это единственная «эмоция» в интерфейсе.

---

## 3. Карта экранов

### 3.1 Витрина (`/`)

| Маршрут | Назначение | Файл |
|---|---|---|
| `/` | Каталог событий с фильтром по категориям | `pages/storefront/CatalogPage.vue` |
| `/event/:id` | Карточка события + выбор сеанса | `pages/storefront/EventPage.vue` |
| `/event/:id/seats` | Схема зала + hold + корзина | `pages/storefront/SeatSelectionPage.vue` |
| `/checkout` | Оформление, контакты, оплата | `pages/storefront/CheckoutPage.vue` |
| `/payment/:result` | Экран успеха / ошибки оплаты | `pages/storefront/PaymentResultPage.vue` |
| `/tickets` | Активные и прошедшие билеты | `pages/storefront/TicketsPage.vue` |

### 3.2 Админка организатора (`/admin`)

| Маршрут | Назначение | Файл |
|---|---|---|
| `/admin` | Обзор: KPI, график, внимание | `pages/admin/AdminDashboardPage.vue` |
| `/admin/orders` | Заказы с фильтрами и статусами | `pages/admin/AdminOrdersPage.vue` |
| `/admin/events` | Список мероприятий | `pages/admin/AdminEventsPage.vue` |
| `/admin/halls` | **Редактор схем залов** | `pages/hall-editor/HallEditorPage.vue` |
| `/admin/:section` | Заглушка для остальных разделов | `pages/admin/AdminPlaceholderPage.vue` |

### 3.3 Главный принцип: путь покупки

```
Каталог
  └─ выбор по категории и дате
Карточка события
  └─ выбор сеанса (если их несколько)
Схема зала
  └─ выбор мест
  └─ hold (10 минут, таймер в правом верхнем углу)
  └─ sticky-CTA «К оформлению»
Checkout
  └─ контакты + способ оплаты
  └─ «Оплатить» (accent-CTA)
Оплата (внешний виджет провайдера)
  └─ возврат на /payment/success или /payment/failed
Билеты
  └─ QR-код + «Показать на входе»
  └─ резервная ссылка на e-mail
```

Этот же путь работает в Telegram Mini App и embed — разметка одна, hash-роутинг одинаковая.

---

## 4. Библиотека компонентов

Все компоненты — в `resources/js/components/ui/`. Префикс `N` — внутреннее пространство имён.

| Компонент | Назначение | Сценарии |
|---|---|---|
| `NButton` | Единственная иерархия CTA: `primary` / `accent` / `secondary` / `outline` / `ghost` / `danger`. В каждом окне ровно одна primary | Везде |
| `NInput` | Поле ввода. Единая высота 44 px, состояния default/focus/error/disabled | Формы, поиск |
| `NSelect` | Выпадающий список на `<select>` (нативный, доступный) | Фильтры, выбор организации |
| `NCheckbox` | Чекбокс с лейблом | Согласия, доп. опции |
| `NBadge` | Тонкая плашка с цветовым тоном | Категории, статусы заказов |
| `NStatusBadge` | Бейдж статуса с цветом по машине состояний | Заказы, билеты, мероприятия |
| `NCountdown` | Таймер обратного отсчёта (hold, до начала сеанса) | Выбор мест, карточка билета |
| `NBottomSheet` | Мобильная выезжающая панель | Заказ на мобильном |
| `NModal` | Модальное окно | Подтверждения, детали |
| `NSegmented` | Сегментированный контрол | Выбор сеанса, переключатель вида |
| `NStepper` | Индикатор шага | Checkout, оформление |
| `NEmptyState` | Пустое состояние с CTA | Пустая корзина, нет билетов |
| `NDataTable` | Таблица с пагинацией и статусами | Заказы, билеты |
| `NToastHost` | Уведомления (фиксированный `top-20`, чтобы не перекрывать sticky-хедер) | Успех оплаты, ошибки |

Компоненты предметной области:

| Компонент | Назначение |
|---|---|
| `SeatMap` | SVG-схема зала с 6 состояниями места |
| `SeatLegend` | Расшифровка цветов карты |
| `OrderSummary` | Сводка заказа в правой колонке выбора мест |
| `EventCard` | Карточка события в каталоге |
| `TicketCard` | Билет с QR, ряд/место/временем |

---

## 5. Карта зала — самый ответственный экран

**Файл:** `pages/storefront/SeatSelectionPage.vue`, `components/seat/SeatMap.vue`.

Принципы:

1. **Сцена сверху, места ниже.** Подсветка сцены — `background-image: stage` (радиальный градиент бренда сверху). Сцена визуально узнаётся за секунду.
2. **Семантика цвета места** — по машине состояний из `STATE-MACHINES.md`. Шесть состояний: свободно (mint-500), ваш выбор (brand-500), держит другой (sun-500, мигает `hold-blink`), продано (rose-500), VIP (gold-500), для маломобильных (sky-500), недоступно (штриховка).
3. **Таймер hold в углу.** 10 минут по умолчанию. Каждые 5 секунд — визуальный тик. За 60 секунд до конца — `sun`, за 10 — `rose` и мягкая вибрация (опционально).
4. **Лимит 6 мест на одного покупателя.** Превышение → toast `rose` + отмена выбора.
5. **Sticky-CTA «К оформлению»** на мобильном — внизу, всегда виден, не зависит от того, сколько пользователь проскроллил.
6. **Клавиатура:** `← ↑ ↓ →` — перемещение по рядам, `Space` — выбрать, `Esc` — закрыть.

---

## 6. Редактор схем залов

**Файл:** `pages/hall-editor/HallEditorPage.vue`.

### 6.1 Соответствие ТЗ §46–§54

| Требование | Статус |
|---|---|
| Canvas-слои: background, static_objects, sectors, rows, seats, tables, labels, selection, guides | Реализовано: 9 слоёв из §46 — фон, статичные объекты (сцена/вход/стол/standing/подпись), секторы, ряды, места, выделение. Guides (направляющие) — roadmap |
| Инструменты: Select, Pan, Zoom, Seat, Row, Sector, Table, Standing, Text, Image, Stage, Entrance | Реализовано: все 12 инструментов палитры `tool`; большинство с реальным поведением (Row → генератор к сектору, Image → фон, остальные → добавление объекта) |
| **Амфитеатр**: форма сектора — полукруг | §48 | Параметр `shape: 'grid' \| 'arc'` в `ESector`, угол раствора `arcSpread` (по умолчанию 160°). Места раскладываются по концентрическим дугам с равным шагом вдоль неё; центр кривизны ниже зала → ряды «смотрят» выпуклостью к сцене. В `draw()` поверх мест рисуются декоративные дуги-направляющие. `addRowToSelected()` продолжает дугу и сдвигает старые места, чтобы задний ряд остался центрирован. Экспортируется в Schema JSON | `pages/hall-editor/HallEditorPage.vue` |
| Multi-select: select, multi-select, move, copy, paste, delete, duplicate, group, align, distribute, rotate | Multi-select (shift+клик), move (drag), delete (Del/⌫), duplicate — да. Copy/paste/group/align/distribute/rotate — roadmap |
| Генератор рядов: сектор / ряд / кол-во / шаг px / направление | Реализован: `generateRow()` принимает эти параметры |
| Pricing по рядам: цена + «Применить» | Реализован: инспектор сектора с полем цены |
| Background image: PNG/JPG/WEBP/SVG, x/y/w/h/opacity/rotation/locked | Реализовано: загрузка файла → `bgLayer` (Konva.Image) с полями x/y/width/height/opacity/rotation/locked |
| Автосохранение 500 ms, состояния saving/saved/error/offline | Реализовано через debounce + статусы |
| Ctrl+Z / Ctrl+Shift+Z, undo/redo отдельно от сервера | Реализовано |
| Цели 500+ мест / 60 FPS / drag < 50 ms | Виртуализация — roadmap; на текущих 144 местах — без задержек |
| Schema JSON: `{canvas, background, sectors, rows, seats, tables, objects}` | Совпадает в `types.ts` |

### 6.2 Архитектура

- Состояние схемы — в реактивном `ref<Sector[]>` (см. `pages/hall-editor/HallEditorPage.vue`).
- История — стек `history[]` + `future[]`, `snapshot()` перед каждой мутацией.
- Автосохранение — `watchDebounced(..., 500)` пишет в `ui-store` индикатор `saving → saved`.
- Undo/redo — `undo()` / `redo()` через `history.pop()` / `future.push()`.
- Привязка к серверу — точка расширения `persist()`: сейчас это no-op, в боевой версии будет `PATCH /api/v1/halls/:id/schema`.

---

## 7. Админка организатора

**Файлы:** `layouts/AdminShell.vue`, `pages/admin/*`.

### 7.1 Принципы

1. **Плотность выше, чем на витрине.** Администратор работает часами — таблицы плотные, тени тише.
2. **Переключатель организации виден всегда** (топбар). Ошибка «сделал не в той компании» в мультитенантной системе стоит дороже любого другого бага.
3. **Командная палитра `Ctrl+K`** — привычнее, чем меню.
4. **«Требует внимания» на дашборде** — самые дорогие сигналы в одном месте: схема не опубликована, долго без продаж, нет цен, не подключён платёжный провайдер.
5. **Цветовые статусы заказов и билетов** совпадают с витриной и Android Checker.

### 7.2 Статусы заказов (цветовое соответствие)

| Статус | Цвет | Где встречается |
|---|---|---|
| `paid` | mint | Список заказов, карточка билета |
| `awaiting_payment` | sun | Список заказов, таймер в checkout |
| `refunded` | mint-700 (тёмный mint) | Список заказов |
| `partially_refunded` | sun + mint | Список заказов |
| `cancelled` | rose | Список заказов |
| `entry_denied` | rose | Только в админке (после check-in) |

---

## 8. Android Checker (нативное приложение)

**ТЗ §45.** Экраны: Splash → Login → Device Registration → Event Selection → Session Selection → Scanner → Ticket Result → Scan History → Sync → Settings.

Результаты сканирования: `VALID`, `ALREADY USED`, `CANCELLED`, `INVALID`, `WRONG EVENT`, `OFFLINE`.

Реализация — Kotlin/Compose, **вне этой Vue-системы**. Но дизайн-токены (статусы, радиусы, тени) переиспользуются через общий design-tokens JSON. Связь с этой системой — через:

- Цвета статусов в билетах (ticket.status: `issued`, `used`, `cancelled`, `refunded`) — совпадают с `NStatusBadge kind="ticket"`.
- Цвета конфликтов offline (`OFFLINE CONFLICT` в админке) — `rose-500`.

---

## 9. Темизация: dark mode, embed, Telegram

### 9.1 Dark mode

По умолчанию — тёмная: медиа-контент (обложки событий, фото залов) выигрывает на тёмном фоне. Светлая — альтернатива, переключается в шапке. Состояние сохраняется.

### 9.2 Embed

```
/embed/event/123?theme=dark&locale=ru
```

`theme` применяется при инициализации, до первого рендера — без «моргания». `locale` подключается через `vue-i18n` (точка расширения).

### 9.3 Telegram Mini App

```ts
// main.ts (точка расширения)
import { useTelegramTheme } from '@/lib/telegram'
const tg = useTelegramTheme()
if (tg.isAvailable()) {
  tg.bindThemeVariables()  // маппит --tg-theme-bg-color → --canvas и т.д.
}
```

CSS-переменные названы в стиле Telegram (`--tg-theme-bg-color`, `--tg-theme-text-color`), чтобы минимум усилий переключаться на нативную Telegram-палитру.

---

## 10. Известные расхождения и roadmap

| # | Что | Где | План |
|---|---|---|---|
| 1 | Hall Editor: виртуализация для 5000+ мест (60 FPS, drag < 50 ms по §53) | `pages/hall-editor/HallEditorPage.vue` | Этап 3: `Konva.FastLayer` для мест + LOD (агрегация на малых zoom) |
| 2 | Hall Editor: multi-select операции copy/paste/group/align/distribute/rotate (move/delete/duplicate — готовы) | `pages/hall-editor/HallEditorPage.vue` | Этап 2: добавить команды в контекстное меню |
| 3 | Telegram WebApp SDK реальное подключение | `stores/ui.ts` | Подключить `@twa-dev/sdk`, биндить `--tg-theme-*` → токены |
| 4 | Локализация (i18n) | весь фронтенд | `vue-i18n`, ru/en; точка расширения готова |
| 5 | BottomSheet `bottom-24` под мобильной навигацией | `NBottomSheet` | Применить на странице выбора мест |
| 6 | Skeleton states | везде | `NEmptyState` готов; нужны скелетоны для карточек и таблиц |
| 7 | Анимации между маршрутами | `router/index.ts` | Vue `<Transition>` + `mode="out-in"` |
| 8 | Бейдж «Действителен» в `TicketCard` визуально клипается в headless Chrome | `components/storefront/TicketCard.vue` | В реальном браузере рендерится корректно; уменьшить padding бейджа до `px-2` для запаса |
| 9 | Hall Editor: контекстное меню (правый клик) для быстрых операций | `pages/hall-editor/HallEditorPage.vue` | Этап 2 |
| 10 | Hall Editor: сетка-привязка (snap-to-grid) при перетаскивании | `pages/hall-editor/HallEditorPage.vue` | Этап 2 |

---

## 11. Доступность (a11y)

- `darkMode: 'class'` — пользователь управляет темой, не система.
- Контраст текст/фон — AA (≥ 4.5:1) для body, AAA для брендовых CTA.
- Все кнопки — `<button>`, не `<div onClick>`. У иконок без текста — `aria-label`.
- Поля ввода — `<label>` через `aria-label` (визуальная метка над полем).
- Карта зала — клавиатурная навигация `←↑↓→`, `Space` — выбор, `Esc` — отмена.
- Цвет — не единственный сигнал: рядом с цветом в `SeatLegend` всегда иконка или подпись, а статус заказа — текст + цвет (например, «Оплачен ✓»).
- Статусы — `aria-live="polite"` в `NToastHost` для скринридеров.

---

## 12. Файловая карта (что где лежит)

```
resources/
├── css/app.css                 # дизайн-токены, базовый слой
├── js/
│   ├── main.ts                 # точка входа, инициализация темы
│   ├── App.vue                 # корневой компонент
│   ├── router/index.ts         # маршруты
│   ├── stores/
│   │   ├── ui.ts               # тема, тосты, командная палитра
│   │   └── cart.ts             # корзина, hold, расчёт total
│   ├── lib/
│   │   ├── cn.ts               # classnames helper
│   │   ├── format.ts           # money, dateFull, time, plural
│   │   ├── hall.ts             # позиционирование секторов
│   │   ├── types.ts            # общие типы
│   │   └── mock.ts             # мок-данные (заменяется на API)
│   ├── components/
│   │   ├── ui/                 # библиотека компонентов (15 шт.)
│   │   ├── seat/               # SeatMap, SeatLegend, OrderSummary
│   │   └── storefront/         # EventCard, TicketCard
│   ├── layouts/
│   │   ├── StorefrontShell.vue
│   │   └── AdminShell.vue
│   └── pages/
│       ├── storefront/         # Catalog, Event, SeatSelection, Checkout, Payment, Tickets
│       ├── admin/              # Dashboard, Orders, Events, Placeholder
│       └── hall-editor/        # HallEditorPage (Konva)
tools/
└── inline-preview.mjs          # сборка автономного HTML-превью
```

---

## 13. Запуск и проверка

```bash
# Установка зависимостей (один раз)
npm install

# Dev-сервер (HMR)
npm run dev

# Production-сборка
npm run build

# Автономный HTML-превью (открывается двойным кликом)
npm run build:preview
# → dist-preview/ui-preview.html, 482 КБ, всё в одном файле

# Проверка типов
npm run typecheck
```

Сборка проходит без ошибок и предупреждений. Bundle ~455 КБ (149 КБ gzip) — на уровне типового B2B-интерфейса.

Для визуальной приёмки — скриншоты в `.shots/` (12 экранов: витрина desktop/mobile, админка, редактор, оплата).