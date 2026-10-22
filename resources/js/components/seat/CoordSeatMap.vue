<script setup lang="ts">
/** Планировка танцпола и столов в «Кассы Югры»: сцена снизу-сверху, танцпол-зона
 *  перед сценой (кликабельна, покупка N билетов), столы/места вокруг.
 *
 *  Новый координатный рендер продаж: SVG с настоящими координатами (x/y),
 *  используется для залов, где известна планировка (Вавилон).
 *
 *  Зум/панорама/клавиатура: раньше эта карта была только «мышью» — у неё не было
 *  ни масштаба, ни перетаскивания, ни `tabindex`, тогда как у рядной `SeatMap` всё
 *  это есть. Для плотного зала без приближения кружки неразличимы, а без
 *  `tabindex` экран недостижим с клавиатуры. Теперь паритет: pinch-zoom,
 *  перетаскивание, колесо, кнопки масштаба и фокус по Tab + Enter/Space.
 *
 *  Мобильный выбор места (проверено на 390 px). Вписанный целиком зал даёт кружок
 *  ~2.3 CSS px — палец по нему не попадает. Яндекс Афиша в той же ситуации не
 *  увеличивает точки, а ставит над схемой чипы ценовых категорий: тап по чипу
 *  оставляет на карте свои места и подтягивает масштаб к ним. Здесь то же самое
 *  плюс невидимая зона нажатия крупнее видимого кружка (`fitSeatHitRadius`) —
 *  тап по месту работает и без предварительного зума.
 */
import { computed, ref, watch } from 'vue'
import { money, plural } from '@/lib/format'
import type { InventoryItem } from '@/lib/inventory'
import { seatStateFromStatus, isSeatPickable } from '@/lib/seatStatus'
import { buildSeatPricePalette, groupTableSeatPoints, type TableMark } from '@/lib/hall'
import {
  focusTransform,
  fitPoints,
  fitSeatRadius,
  fitSeatHitRadius,
  pointBounds,
  projectPoint,
} from '@/lib/seatViewport'
import { DANCE_BOX_HEIGHT, danceZonesLayout, type DanceZoneText } from '@/lib/danceZone'

/** Палитра состояний — согласована с CSS-классами seat--* и основной легендой. */
const STATE_FILL: Record<string, string> = {
  free: '#8E74FF',
  selected: '#C9A0FF',
  held: '#F0B429', // «держит другой» — янтарный, как seat--held в SeatMap
  sold: '#5a3f66',
  unavailable: '#3a3050',
}

const LEGEND_ITEMS: Array<{ state: string; label: string }> = [
  { state: 'selected', label: 'выбрано' },
  { state: 'held', label: 'держит другой' },
  { state: 'sold', label: 'продано' },
  { state: 'unavailable', label: 'недоступно' },
]

const props = defineProps<{
  /** Инвентарь сессии (места + стоячие зоны). */
  inventory: InventoryItem[]
  /** Действие при клике на место. */
  onToggle?: (item: InventoryItem, qty: number) => Promise<void> | void
  /** Действие при выборе зоны танцпола (выбор N билетов). */
  onDanceToggle?: (item: InventoryItem, qty: number) => Promise<void> | void
  /** id выбранных мест. */
  selected?: string[]
  /**
   * Уже выбранных БИЛЕТОВ (не позиций). Танцпол qty=3 — это три билета,
   * поэтому `selected.length` для лимита не годится. Родитель — источник истины.
   */
  selectedCount?: number
  maxQuantity?: number
  /**
   * С какой стороны полотна сцена: `top` (по умолчанию) или `bottom`.
   *
   * Раньше полоса «СЦЕНА» рисовалась всегда сверху. Для зала, где места идут
   * от сцены ВВЕРХ (у «Театра СурГУ» ряд 1 внизу, ряд 16 у задней стены),
   * подпись оказывалась с противоположной стороны — витрина показывала сцену
   * за спиной зрителя. Направление приходит из схемы зала через
   * `meta.stage_side` инвентаря: по местам его не угадать.
   */
  stageSide?: 'top' | 'bottom'
}>()

const emit = defineEmits<{ toggle: [item: InventoryItem, qty: number]; limit: [] }>()

const selectedIds = computed(() => new Set(props.selected ?? []))

/** Билетов выбрано: явный пропс, иначе — число позиций. */
const selectedTickets = computed(() => {
  const explicit = Number(props.selectedCount)
  return Number.isFinite(explicit) ? explicit : (props.selected?.length ?? 0)
})

/** Лимит билетов на заказ (сервер: `CartController::addItem`, quantity max:10). */
const maxTickets = computed(() => {
  const m = Number(props.maxQuantity)
  return Number.isFinite(m) && m > 0 ? m : Number.POSITIVE_INFINITY
})

/** Полотно SVG и отступы. */
const SVG_W = 620
const SVG_H = 400
const PAD = 30
/** Полоса сцены сверху: места не должны в неё заезжать. */
const STAGE_H = 46
/** Радиус адаптируется к шагу после нормализации данных зала. */
/**
 * Полоса зоны танцпола снизу и зазор до ближайшего места.
 *
 * Зона рисуется как НАЛОЖЕНИЕ: у стоячей позиции в `inventory_items` нет
 * геометрии (`x = y = 0`, вместимость в `capacity`), поэтому её место на карте
 * выбирает витрина. Раньше она ставилась в центр полотна (`y = 70`) и
 * накладывалась на места: на концертном зале амфитеатр рисовался прямо поверх
 * пунктирной рамки, подписи зоны было не прочитать, а клик по зоне перехватывали
 * места. Теперь под зону зарезервирована нижняя полоса: места вписываются в
 * оставшуюся высоту и физически не могут её занять.
 */
const DANCE_GAP = 14
/**
 * Высота пикера количества билетов (44) плюс зазор. Пикер раскрывается НАД
 * зоной: под ней уже край полотна. Пока он открыт, столько же высоты
 * дополнительно резервируется, чтобы он не наложился на места.
 */
const DANCE_PICKER_RESERVE = 52

/** Сцена — сверху по центру. */
const stageAtBottom = computed(() => props.stageSide === 'bottom')

/**
 * Полоса сцены. Положение зависит от зала: у одних схем места уходят вверх от
 * сцены (сцена снизу), у других — вниз (сцена сверху). Источник истины —
 * статичный объект `kind: "stage"` в `schema_json`; сервер сводит его в
 * `meta.stage_side`, потому что витрине инвентарь приходит без схемы зала.
 */
const stage = computed(() => ({
  x: (SVG_W - 200) / 2,
  y: stageAtBottom.value ? SVG_H - 12 - 30 : 12,
  w: 200,
  h: 30,
}))

/** Места (seat) с координатами. */
const seats = computed(() =>
  props.inventory.filter((i) => i.type === 'seat' && i.seat),
)

const priceColors = computed(() => buildSeatPricePalette(seats.value.map((item) => Number(item.price_amount ?? 0))))

/**
 * Чипы ценовых категорий — цветовой легендой, которой можно тапать.
 *
 * Зачем. Проверено на виджете Яндекс Афиши (экран 390×844): вписанная целиком
 * схема даёт точки ~3 CSS px, и палец по такому месту не попадает. Яндекс не
 * увеличивает точки, а предлагает выбрать ценовую категорию: тап по чипу оставляет
 * на карте только свои места и подтягивает масштаб к ним. Тем же занимаемся и мы:
 * чип = фильтр + приближение, а «Все цены» возвращает весь зал.
 *
 * На десктопе чипы остаются обычной легендой: там курсор мыши попадает и по
 * мелкой точке, а фильтр — приятная необязательность.
 */
const priceChips = computed(() => {
  const counts = new Map<number, number>()
  for (const seat of seats.value) {
    const price = Math.round(Number(seat.price_amount ?? 0))
    counts.set(price, (counts.get(price) ?? 0) + 1)
  }
  return [...counts.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([price, count]) => ({
      price,
      count,
      color: priceColors.value.get(price) ?? STATE_FILL.free,
    }))
})

/** Выбранная ценовая категория: `null` — показываем весь зал. */
const activePrice = ref<number | null>(null)

/** Цена места в том же округлении, что и в палитре чипов. */
function seatPrice(item: InventoryItem): number {
  return Math.round(Number(item.price_amount ?? 0))
}

/**
 * Место не из активной категории затемняется и перестаёт быть кликабельным.
 *
 * Затемнение, а не скрытие: покупатель должен видеть, где в зале находятся
 * отфильтрованные места, иначе он теряет ориентир. Кликабельность снимаем, чтобы
 * промах мимо мелкой точки не выбирал место «не той цены» — при активном фильтре
 * это была бы неожиданность.
 */
function isDimmed(item: InventoryItem): boolean {
  return activePrice.value !== null && seatPrice(item) !== activePrice.value
}

/**
 * Приблизить карту к местам выбранной категории.
 *
 * Считается по габаритам ТОЛЬКО этих мест, поэтому после тапа по чипу масштаб
 * подтягивается ровно к ним, а не к залу целиком. Когда чип снят — прежний
 * `resetView()`.
 */
function focusPriceChip(price: number | null): void {
  if (price === null) {
    resetView()
    return
  }
  const element = viewport.value
  if (!element) return

  const matching = seats.value.filter((seat) => seatPrice(seat) === price)
  if (matching.length === 0) return

  const projected = matching.map((seat) => projectPoint(
    Number(seat.seat?.x ?? 0),
    Number(seat.seat?.y ?? 0),
    fit.value,
  ))

  const focus = focusTransform(
    pointBounds(projected),
    { width: element.clientWidth, height: element.clientHeight },
    { width: SVG_W, height: SVG_H },
    { minZoom: MIN_ZOOM, maxZoom: MAX_ZOOM, fill: 0.78 },
  )

  zoom.value = clampZoom(focus.zoom)
  panX.value = Math.round(focus.panX)
  panY.value = Math.round(focus.panY)
}

function selectPriceChip(price: number | null): void {
  activePrice.value = price === activePrice.value ? null : price
  focusPriceChip(activePrice.value)
}

/**
 * Инвентарь подгружается асинхронно и обновляется после каждого удержания. Если
 * активной категории в новом наборе нет (сеанс переключили, цену поменяли), фильтр
 * обязан сняться: иначе затемнены ВСЕ места и выбрать нельзя ничего.
 */
watch(priceChips, (chips) => {
  if (activePrice.value === null) return
  if (!chips.some((chip) => chip.price === activePrice.value)) {
    activePrice.value = null
    resetView()
  }
})

/** Центр и номер банкетного стола вычисляются из кольца мест — отдельная геометрия API не нужна. */
const tableMarks = computed(() => groupTableSeatPoints(
  seats.value.map((item) => ({
    sectorName: item.seat?.sector_name,
    x: Number(item.seat?.x ?? 0),
    y: Number(item.seat?.y ?? 0),
  })),
))

function tablePixel(mark: TableMark): { x: number; y: number; radius: number } {
  const center = projectPoint(mark.x, mark.y, fit.value)
  return {
    ...center,
    radius: Math.max(4, Math.min(12, mark.ring * fit.value.scale * 0.68)),
  }
}

/**
 * Габариты и масштаб берём из ДАННЫХ, а не из констант.
 *
 * Здесь стояли `SCALE_X = SCALE_Y = 10` и жёсткая формула `ny = 40 - sy`
 * («данные 0..40»). Для настоящей схемы зала это неверно: у
 * `hall_schema_versions.schema_json` полотно 900x520, а места стоят на y = 60
 * (ряд 1) и y = 110 (ряд 2). Тогда `ny = 40 - 60 = -20`, и `y = 30 + (-20 * 10)
 * = -170` — координата уходит ЗА верхнюю границу viewBox. Итог: карта рисовала
 * пустой зал с одной надписью «СЦЕНА», хотя мест было 10, и выбрать место
 * (главное действие витрины) было физически невозможно. Проверено скриншотом
 * живого стенда: `#/event/<slug>/seats?session=1`.
 *
 * Сама математика вынесена в `@/lib/seatViewport` (P2): она чистая, тестируется
 * без монтирования компонента и больше не прячется внутри `computed`-цепочки.
 */
const fit = computed(() =>
  fitPoints(
    seats.value.map((s) => ({ x: Number(s.seat?.x ?? 0), y: Number(s.seat?.y ?? 0) })),
    {
      width: SVG_W,
      height: SVG_H,
      pad: PAD,
      // Полоса сцены резервируется с той стороны, где она нарисована: иначе
      // места либо заезжают на подпись, либо половина полотна пустует.
      reservedTop: stageAtBottom.value ? 0 : STAGE_H,
      // Нижняя полоса отдана зонам танцпола (и пикеру количества, когда он
      // раскрыт) — иначе места ложатся на зону.
      reservedBottom: (stageAtBottom.value ? STAGE_H : 0) + (danceZones.value.length > 0
        ? DANCE_BOX_HEIGHT + DANCE_GAP + (pickerZoneId.value !== null ? DANCE_PICKER_RESERVE : 0)
        : 0),
    },
  ),
)
const seatRadius = computed(() => fitSeatRadius(
  seats.value.map((item) => ({ x: Number(item.seat?.x ?? 0), y: Number(item.seat?.y ?? 0) })),
  fit.value.scale,
))

/**
 * Радиус прозрачной зоны нажатия — крупнее видимого кружка, но без перекрытия
 * соседей (потолок 45% шага между местами, см. `fitSeatHitRadius`).
 *
 * Без неё на 390 px кружок = 2.3 CSS px, и тап по месту — лотерея. Зона
 * нажатия рисуется ПОД видимым кружком, а видимому выставляется
 * `pointer-events: none`, поэтому весь тап по месту ловится ею.
 */
const hitRadius = computed(() => fitSeatHitRadius(
  seats.value.map((item) => ({ x: Number(item.seat?.x ?? 0), y: Number(item.seat?.y ?? 0) })),
  fit.value.scale,
))

/**
 * Координаты места → пиксели SVG.
 *
 * Y НЕ инвертируем — см. докблок `fitPoints()`, там же объяснено, почему
 * инверсия меняла ряды местами относительно редактора зала.
 */
function px(item: InventoryItem): { x: number; y: number; n: number } {
  const { x, y } = projectPoint(
    Number(item.seat?.x ?? 0),
    Number(item.seat?.y ?? 0),
    fit.value,
  )

  return { x, y, n: Number(item.seat?.number ?? 0) }
}

/**
 * Стоячие зоны (танцпол). Их бывает НЕСКОЛЬКО: у «Вавилона» две admission-
 * категории — «Танцпол + ранний вход» и обычный «Танцпол», с разными ценами и
 * разными остатками. Раньше бралась только первая (`find`), и вторая
 * категория не отображалась на витрине вовсе: её билеты было невозможно
 * купить. Теперь зоны раскладываются рядом в нижней полосе.
 */
const danceZones = computed(() => props.inventory.filter((i) => i.type === 'standing'))

/** Суммарный остаток стоячих билетов — для строки-сводки над картой. */
const standingTotal = computed(() => danceZones.value
  .reduce((sum, zone) => sum + Number(zone.available_quantity ?? 0), 0))

/** Название зоны: схема зала кладёт имя сектора в метаданные инвентаря. */
function zoneTitle(zone: InventoryItem): string {
  const meta = zone.metadata_json
  const name = meta && typeof meta === 'object' ? (meta as Record<string, unknown>).sector_name : null
  return name ? String(name) : 'Танцпол'
}

const isZoneSelected = (zone: InventoryItem): boolean => selectedIds.value.has(String(zone.id))

/**
 * Строки подписи зоны. Цена и остаток — РАЗНЫЕ строки: раньше они были
 * склеены в одну («5 000 ₽ · 150 билетов»), и её ширина росла с разрядностью
 * чисел, вылезая за жёсткую рамку 150×90.
 */
function zoneText(zone: InventoryItem): DanceZoneText {
  const count = Number(zone.available_quantity ?? 0)
  return {
    title: zoneTitle(zone),
    price: money(zone.price_amount),
    count: `${count} ${plural(count, 'билет', 'билета', 'билетов')}`,
    hint: isZoneSelected(zone) ? 'выбрано — клик, чтобы убрать' : 'клик — выбрать билеты',
  }
}

/**
 * Рамки зон: ширина считается по тексту (см. lib/danceZone.ts), полоса
 * делится на равные слоты, поэтому подпись всегда помещается внутрь.
 */
const zoneBoxes = computed(() => {
  const zones = danceZones.value
  const boxes = danceZonesLayout(zones.map(zoneText), {
    canvasWidth: SVG_W,
    y: SVG_H - PAD - DANCE_BOX_HEIGHT,
    gap: 10,
  })
  return zones.map((zone, i) => ({
    zone,
    box: boxes[i],
    text: zoneText(zone),
    selected: isZoneSelected(zone),
  }))
})

/** Пикер количества открыт не более чем у одной зоны: второй некуда поставить. */
const pickerZoneId = ref<string | null>(null)
/** Подсветка последнего выбранного количества — локальное состояние пикера. */
const danceQty = ref(2)

/**
 * Сколько билетов можно предложить в пикере.
 *
 * Ограничивают три вещи: свободный остаток зоны (`available_quantity`),
 * остаток лимита на заказ и разумный максимум интерфейса (5).
 */
function zoneOptions(zone: InventoryItem): number[] {
  const byCapacity = Number(zone.available_quantity)
  const room = maxTickets.value - selectedTickets.value
  const cap = Math.min(5, Number.isFinite(byCapacity) ? byCapacity : 5, room)
  return Array.from({ length: Math.max(0, Math.floor(cap)) }, (_, i) => i + 1)
}

function toggleZone(zone: InventoryItem): void {
  // Повторный клик по уже выбранной зоне — снятие. Раньше здесь стоял
  // `return`: зона оставалась выбранной навсегда, и после удаления её из
  // сводки повторно выбрать танцпол было уже нельзя до перезагрузки.
  if (isZoneSelected(zone)) {
    pickerZoneId.value = null
    emit('toggle', zone, 1)
    return
  }
  if (selectedTickets.value >= maxTickets.value) {
    emit('limit')
    return
  }
  pickerZoneId.value = String(zone.id)
}

function pickZone(zone: InventoryItem, qty: number): void {
  // Лимит проверяем ДО отправки: сервер откажет 422-м на quantity > 10, и
  // покупатель увидит техническую ошибку вместо понятного «не больше 10».
  if (selectedTickets.value + qty > maxTickets.value) {
    emit('limit')
    return
  }
  danceQty.value = qty
  pickerZoneId.value = null
  emit('toggle', zone, qty)
}

/* ── Масштаб и панорама ─────────────────────────────────────────────────
 * `transform-origin: top left` (как в SeatMap): при таком origin математика
 * зума и панорамы линейна и не зависит от размеров вьюпорта.
 *
 * Верхняя граница 8, а не 3: после тапа по чипу цены масштаб подтягивается к
 * местам одной категории (`focusPriceChip`), а их габариты могут быть втрое
 * меньше зала. При потолке 3 масштаб не дотягивался, и точки оставались
 * мелкими — то есть чип не решал задачу, ради которой появился.
 */
const MIN_ZOOM = 0.6
const MAX_ZOOM = 8

const viewport = ref<HTMLElement | null>(null)
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const dragging = ref(false)

function clampZoom(value: number): number {
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number(value.toFixed(3))))
}

/** Масштаб относительно центра видимой области — место «под пальцем» не убегает. */
function zoomBy(factor: number): void {
  const el = viewport.value
  const next = clampZoom(zoom.value * factor)
  if (el && next !== zoom.value) {
    const cx = el.clientWidth / 2
    const cy = el.clientHeight / 2
    const ratio = next / zoom.value
    panX.value = cx - (cx - panX.value) * ratio
    panY.value = cy - (cy - panY.value) * ratio
  }
  zoom.value = next
}

function resetView(): void {
  zoom.value = 1
  panX.value = 0
  panY.value = 0
}

/* Активные указатели: 1 — панорама, 2 — pinch-zoom. */
const pointers = new Map<number, { x: number; y: number }>()
let dragFrom: { x: number; y: number; px: number; py: number } | null = null
let pinchStart: { dist: number; zoom: number } | null = null
/** Пользователь тащил карту: следующий `click` не должен выбирать место. */
let dragMoved = false

function pointerList(): Array<{ x: number; y: number }> {
  return [...pointers.values()]
}

function onPointerDown(event: PointerEvent): void {
  dragMoved = false
  pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

  if (pointers.size === 2) {
    const [a, b] = pointerList()
    pinchStart = { dist: Math.hypot(a.x - b.x, a.y - b.y), zoom: zoom.value }
    dragFrom = null
    dragging.value = false
    return
  }

  if (pointers.size === 1) {
    dragFrom = { x: event.clientX, y: event.clientY, px: panX.value, py: panY.value }
    dragging.value = true
  }
}

function onPointerMove(event: PointerEvent): void {
  if (!pointers.has(event.pointerId)) return
  pointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

  // Pinch: отношение текущей дистанции к стартовой задаёт масштаб.
  if (pointers.size >= 2 && pinchStart) {
    const [a, b] = pointerList()
    const dist = Math.hypot(a.x - b.x, a.y - b.y)
    if (pinchStart.dist > 0) {
      zoom.value = clampZoom(pinchStart.zoom * (dist / pinchStart.dist))
      dragMoved = true
    }
    return
  }

  if (!dragFrom) return
  const dx = event.clientX - dragFrom.x
  const dy = event.clientY - dragFrom.y
  // Порог 4px: дрожание пальца при тапе не должно отменять выбор места.
  if (Math.abs(dx) + Math.abs(dy) > 4) dragMoved = true
  panX.value = dragFrom.px + dx
  panY.value = dragFrom.py + dy
}

function onPointerUp(event: PointerEvent): void {
  pointers.delete(event.pointerId)
  if (pointers.size < 2) pinchStart = null
  if (pointers.size === 0) {
    dragFrom = null
    dragging.value = false
  }
}

function onWheel(event: WheelEvent): void {
  zoomBy(event.deltaY < 0 ? 1.12 : 1 / 1.12)
}

/* ── Клавиатура ─────────────────────────────────────────────────────────── */

const focusedId = ref<string | null>(null)

function activateByKey(event: KeyboardEvent, item: InventoryItem): void {
  if (event.key !== 'Enter' && event.key !== ' ') return
  event.preventDefault()
  toggleSeat(item)
}

function toggleSeat(item: InventoryItem): void {
  // После перетаскивания карты `click` не должен выбирать место.
  if (dragMoved) return
  const id = String(item.id)
  const isSelected = selectedIds.value.has(id)
  // Проданное/занятое/заблокированное место не кликается — сервер всё равно
  // откажет (409), а пользователю показываем это сразу цветом и курсором.
  if (!isSeatPickable(item.status, item.available_quantity) && !isSelected) return
  // Лимит билетов на заказ: без этой проверки 11-е место уходило на сервер и
  // возвращалось 422 VALIDATION_ERROR вместо понятного сообщения.
  if (!isSelected && selectedTickets.value >= maxTickets.value) {
    emit('limit')
    return
  }
  emit('toggle', item, 1)
}

/** Единый маппинг статусов — та же функция, что использует SeatMap. */
function seatState(item: InventoryItem): string {
  return seatStateFromStatus(item.status, selectedIds.value.has(String(item.id)), item.available_quantity)
}

/**
 * Заливка кружка. Для `held` — штриховой паттерн, а не сплошной янтарный:
 * состояние должно читаться не только цветом (доступность при дальтонизме),
 * и совпадать по смыслу с CSS-классом `.seat--held` рядной карты.
 */
function seatFill(item: InventoryItem): string {
  const state = seatState(item)
  if (state === 'held') return 'url(#heldHatch)'
  if (state === 'free') {
    return priceColors.value.get(Math.round(Number(item.price_amount ?? 0))) ?? STATE_FILL.free
  }
  return STATE_FILL[state] ?? STATE_FILL.unavailable
}

function seatTitle(item: InventoryItem): string {
  const row = item.seat?.row_number ?? item.seat?.row_id ?? '?'
  const n = item.seat?.number ?? 0
  const price = money(Number(item.price_amount ?? 0))
  const labels: Record<string, string> = {
    free: `свободно, ${price}`,
    selected: 'выбрано',
    held: 'держит другой покупатель',
    sold: 'продано',
    unavailable: 'недоступно',
  }
  return `Ряд ${row}, место ${n} — ${labels[seatState(item)] ?? 'недоступно'}`
}

/*
 * Подсказка при наведении: сектор, ряд, место и цена.
 *
 * Раньше единственной подсказкой был нативный `<title>` внутри SVG: браузер
 * показывает его через секунду ожидания, системным шрифтом и поверх курсора —
 * на схеме из пятисот мест это бесполезно. Здесь подсказка появляется сразу,
 * едет за курсором и показывает то, по чему человек принимает решение:
 * «Партер · ряд 6 · место 1» и «свободно · 2 800 ₽».
 *
 * Координаты считаем от рамки карты (`mapBox`), а не от SVG: SVG
 * трансформируется зумом и перетаскиванием, а подсказка живёт в обычном
 * слое над ним.
 */
const mapBox = ref<HTMLElement | null>(null)
const tip = ref<{ main: string; sub: string; x: number; y: number } | null>(null)

function tipFor(item: InventoryItem): { main: string; sub: string } {
  const sector = item.seat?.sector_name ?? ''
  const row = item.seat?.row_number ?? item.seat?.row_id ?? '?'
  const n = item.seat?.number ?? 0
  const price = money(Number(item.price_amount ?? 0))
  const labels: Record<string, string> = {
    free: 'свободно',
    selected: 'выбрано',
    held: 'держит другой покупатель',
    sold: 'продано',
    unavailable: 'недоступно',
  }
  const state = seatState(item)
  return {
    main: [sector, `ряд ${row}`, `место ${n}`].filter((p) => p !== '').join(' · '),
    sub: `${labels[state] ?? 'недоступно'} · ${price}`,
  }
}

function showTip(e: MouseEvent, item: InventoryItem): void {
  const box = mapBox.value
  if (box === null) return
  const r = box.getBoundingClientRect()
  tip.value = { ...tipFor(item), x: e.clientX - r.left, y: e.clientY - r.top }
}

function moveTip(e: MouseEvent): void {
  const box = mapBox.value
  if (box === null || tip.value === null) return
  const r = box.getBoundingClientRect()
  tip.value = { ...tip.value, x: e.clientX - r.left, y: e.clientY - r.top }
}

function hideTip(): void {
  tip.value = null
}

function isPickable(item: InventoryItem): boolean {
  return isSeatPickable(item.status, item.available_quantity) || selectedIds.value.has(String(item.id))
}
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-line bg-surface-2">
    <div class="flex flex-col gap-2 border-b border-line px-3 py-2 text-2xs text-subtle">
      <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span>{{ seats.length }} мест<template v-if="danceZones.length"> · стоячие зоны {{ standingTotal }} билетов</template></span>
      </div>

      <!--
        Чипы цен: легенда, которой можно тапать. На 390 px вписанный целиком зал
        даёт кружок места ~2.3 CSS px — палец не попадает. Как в Яндекс Афише:
        выбираешь ценовую категорию, карта оставляет её места и подтягивает к ним
        масштаб; «Все цены» возвращает весь зал. Число справа — сколько мест в
        категории, чтобы не тапать вслепую.
      -->
      <div v-if="priceChips.length > 1" class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="flex min-h-[36px] items-center gap-1.5 rounded-full border px-3 py-1 text-2xs font-medium transition-colors"
          :class="activePrice === null
            ? 'border-transparent bg-surface-3 text-content'
            : 'border-line bg-surface text-subtle hover:text-content'"
          :aria-pressed="activePrice === null"
          @click="selectPriceChip(null)"
        >Все цены</button>
        <button
          v-for="chip in priceChips"
          :key="chip.price"
          type="button"
          class="flex min-h-[36px] items-center gap-1.5 rounded-full border px-3 py-1 text-2xs font-medium transition-colors"
          :class="activePrice === chip.price
            ? 'border-transparent bg-surface-3 text-content'
            : 'border-line bg-surface text-subtle hover:text-content'"
          :aria-pressed="activePrice === chip.price"
          :title="`Показать только места за ${money(chip.price)}`"
          @click="selectPriceChip(chip.price)"
        >
          <i class="inline-block size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: chip.color }" />
          {{ money(chip.price) }}
          <span class="text-layer">· {{ chip.count }}</span>
        </button>
      </div>

      <div class="flex flex-wrap gap-x-3 gap-y-1 text-layer">
        <span v-for="l in LEGEND_ITEMS" :key="l.state" class="flex items-center gap-1.5">
          <i class="inline-block size-2.5 rounded-full" :style="{ background: STATE_FILL[l.state] }" /> {{ l.label }}
        </span>
      </div>
    </div>

    <div ref="mapBox" class="relative">
      <!--
        Масштаб: паритет с SeatMap (там кнопки есть, здесь их не было).
        Размер 44 px, а не 32: это единственный способ приблизить зал на телефоне
        без щипка, а 32 px — мимо пальцем. Рекомендация тапа — не меньше 44.
      -->
      <div class="absolute right-3 top-3 z-20 flex flex-col gap-2">
        <button
          type="button"
          class="grid h-11 w-11 place-items-center rounded-full border border-line bg-surface text-lg font-semibold text-muted shadow-sm transition-colors hover:text-content"
          aria-label="Приблизить"
          @click="zoomBy(1.25)"
        >+</button>
        <button
          type="button"
          class="grid h-11 w-11 place-items-center rounded-full border border-line bg-surface text-lg font-semibold text-muted shadow-sm transition-colors hover:text-content"
          aria-label="Отдалить"
          @click="zoomBy(0.8)"
        >−</button>
        <button
          type="button"
          class="grid h-11 w-11 place-items-center rounded-full border border-line bg-surface text-2xs text-muted shadow-sm transition-colors hover:text-content"
          aria-label="Показать весь зал"
          @click="resetView"
        >⤢</button>
      </div>

      <div
        ref="viewport"
        class="touch-none overflow-hidden"
        :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
        style="height: min(58vh, 460px)"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
        @wheel.prevent="onWheel"
      >
        <!--
          Вьюпорт фиксированной высоты (min(58vh, 460px)) — чтобы карта не
          растягивала страницу. Но SVG был `w-full h-auto`: его высота следовала
          за шириной (620:400), поэтому на десктопе (карта ≈814 px шириной →
          ≈525 px высотой) низ холста уходил за границу и обрезался
          `overflow-hidden`. Зал, разложенный до нижнего края полотна (банкет со
          столами во втором ряду), терял нижний ряд мест — их нельзя было ни
          увидеть, ни выбрать. Теперь SVG занимает ровно вьюпорт, а `meet`
          вписывает весь холст целиком: зал всегда виден полностью, зум и
          перетаскивание работают как раньше.
        -->
        <svg
          :viewBox="`0 0 ${SVG_W} ${SVG_H}`"
          preserveAspectRatio="xMidYMid meet"
          class="block h-full w-full origin-top-left select-none"
          :style="{ transform: `translate(${panX}px, ${panY}px) scale(${zoom})` }"
          role="img"
          aria-label="Схема зала"
        >
          <!-- Сцена -->
          <rect :x="stage.x" :y="stage.y" :width="stage.w" :height="stage.h" rx="5" fill="#F0F2F5" stroke="#D8DCE3" stroke-width="1" />
          <text :x="SVG_W / 2" :y="stage.y + 21" text-anchor="middle" fill="#596273" font-size="12" font-weight="600" letter-spacing="1.2">СЦЕНА</text>

          <!-- Стоячие зоны (танцпол). Их может быть несколько — см. danceZones. -->
          <g v-for="z in zoneBoxes" :key="String(z.zone.id)">
            <rect
              :x="z.box.x" :y="z.box.y" :width="z.box.w" :height="z.box.h"
              rx="14" fill="rgba(240,180,60,0.12)" :stroke="z.selected ? '#C9A0FF' : '#E8B544'" stroke-width="2" stroke-dasharray="8 5"
              class="cursor-pointer transition hover:opacity-80"
              role="button"
              tabindex="0"
              :aria-label="z.selected ? `${z.text.title} выбран, нажмите чтобы убрать` : `${z.text.title}: выбрать количество билетов`"
              :aria-pressed="z.selected"
              @click="toggleZone(z.zone)"
              @keydown.enter.prevent="toggleZone(z.zone)"
              @keydown.space.prevent="toggleZone(z.zone)"
            />
            <text :x="z.box.x + z.box.w / 2" :y="z.box.titleY" text-anchor="middle" fill="#596273" font-size="14" font-weight="700">{{ z.text.title }}</text>
            <text :x="z.box.x + z.box.w / 2" :y="z.box.priceY" text-anchor="middle" fill="#596273" font-size="12">{{ z.text.price }}</text>
            <text :x="z.box.x + z.box.w / 2" :y="z.box.countY" text-anchor="middle" fill="#596273" font-size="12">{{ z.text.count }}</text>
            <text
              v-if="pickerZoneId !== String(z.zone.id)"
              :x="z.box.x + z.box.w / 2" :y="z.box.hintY" text-anchor="middle"
              :fill="z.selected ? '#5B43C6' : '#667181'" font-size="11"
            >{{ z.text.hint }}</text>

            <!-- Селектор количества билетов -->
            <!--
              Пикер раскрывается НАД зоной: под зоной край полотна, и раньше
              он уезжал за него (`danceRect.y + danceRect.h + 20` = 390 при
              высоте 400 — половина кружков обрезалась).
            -->
            <g v-if="pickerZoneId === String(z.zone.id)" :transform="`translate(${z.box.x + z.box.w / 2 - 110}, ${z.box.y - 26})`">
              <rect x="0" y="-18" width="220" height="44" rx="12" fill="#1a1530" stroke="#F0C060" stroke-width="1" />
              <g v-for="(q, i) in zoneOptions(z.zone)" :key="q" @click="pickZone(z.zone, q)" class="cursor-pointer">
                <circle :cx="15 + i * 42" cy="4" :r="14" :fill="q === danceQty ? '#F0C060' : '#2a2140'" stroke="#E8B544" stroke-width="1">
                  <title>Купить {{ q }} {{ plural(q, 'билет', 'билета', 'билетов') }}</title>
                </circle>
                <text :x="15 + i * 42" :y="8" text-anchor="middle" fill="#1a1530" font-size="13" font-weight="700">{{ q }}</text>
              </g>
              <text v-if="zoneOptions(z.zone).length === 0" x="110" y="8" text-anchor="middle" fill="#F0C060" font-size="12">свободных билетов нет</text>
            </g>
          </g>

          <!-- Столешницы строятся под креслами из центра их координатного кольца. -->
          <g v-for="table in tableMarks" :key="table.name" aria-hidden="true">
            <circle
              :cx="tablePixel(table).x"
              :cy="tablePixel(table).y"
              :r="tablePixel(table).radius"
              fill="#F4F5F7"
              stroke="#D5D9E0"
              stroke-width="1"
            />
            <text
              v-if="table.label && tablePixel(table).radius >= 4"
              :x="tablePixel(table).x"
              :y="tablePixel(table).y + 3"
              text-anchor="middle"
              fill="#626B78"
              font-size="8"
              font-weight="600"
            >{{ table.label }}</text>
          </g>

          <!-- Места -->
          <g v-for="s in seats" :key="String(s.id)" class="seat-cell">
            <!--
              Прозрачная зона нажатия — ПОД видимым кружком. На 390 px кружок
              равен 2.3 CSS px, палец по нему не попадает; зона крупнее (потолок
              45% шага до соседа), поэтому тап по месту работает без зума.
              У затемнённых (не из активной ценовой категории) мест зоны нет
              вовсе: промах не должен выбирать место «не той цены».
            -->
            <circle
              v-if="!isDimmed(s)"
              :cx="px(s).x"
              :cy="px(s).y"
              :r="hitRadius"
              fill="transparent"
              class="seat-hit"
              @click="toggleSeat(s)"
              @mouseenter="showTip($event, s)"
              @mousemove="moveTip($event)"
              @mouseleave="hideTip"
            />
            <!--
              Видимый кружок больше не принимает события: всё ловит зона под ним.
              Поэтому hover-подсветку рисуем с родительской ячейки — иначе
              `pointer-events: none` отменил бы и эффект наведения.
            -->
            <circle
              :cx="px(s).x"
              :cy="px(s).y"
              :r="seatRadius"
              :fill="seatFill(s)"
              :stroke="focusedId === String(s.id) ? '#ffffff' : 'none'"
              :stroke-width="focusedId === String(s.id) ? 2.5 : 0"
              :class="[
                'seat-dot',
                isDimmed(s) ? 'seat-dot--dim' : (isPickable(s) ? 'seat-dot--live' : 'seat-dot--off'),
              ]"
              role="button"
              :tabindex="isDimmed(s) ? -1 : 0"
              :aria-label="seatTitle(s)"
              :aria-pressed="selectedIds.has(String(s.id))"
              @keydown="activateByKey($event, s)"
              @focus="focusedId = String(s.id)"
              @blur="focusedId = null"
            />
          </g>

          <defs>
            <linearGradient id="stageGrad" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0" stop-color="#6D4AFF" />
              <stop offset="1" stop-color="#FF5C22" />
            </linearGradient>
            <!-- «Держит другой»: янтарный + горизонтальная штриховка, чтобы
                 состояние не сводилось к одному оттенку. -->
            <pattern id="heldHatch" width="4" height="4" patternUnits="userSpaceOnUse">
              <rect width="4" height="4" fill="#F0B429" />
              <line x1="0" y1="0" x2="4" y2="0" stroke="rgba(0,0,0,0.35)" stroke-width="1.2" />
            </pattern>
          </defs>
        </svg>
      </div>

      <!--
        Подсказка живёт вне вьюпорта (у него `overflow-hidden`, иначе её
        срезало бы на границе карты) и не перехватывает мышь: иначе она сама
        становилась бы «местом» под курсором и гасила hover.
      -->
      <div
        v-if="tip"
        class="pointer-events-none absolute z-30 -translate-x-1/2 -translate-y-[calc(100%+10px)] whitespace-nowrap rounded-lg border border-line bg-surface px-2.5 py-1.5 shadow-lg"
        :style="{ left: `${tip.x}px`, top: `${tip.y}px` }"
        role="status"
        aria-live="polite"
      >
        <p class="text-2xs font-semibold text-content">{{ tip.main }}</p>
        <p class="text-2xs text-muted">{{ tip.sub }}</p>
      </div>

      <p class="border-t border-line px-3 py-1.5 text-2xs text-subtle">
        Перетаскивание и щипок — приблизить зал. Кнопки справа — масштаб.
        <template v-if="priceChips.length > 1">Тап по цене сверху оставляет на схеме только её места и приближает их.</template>
      </p>
    </div>
  </div>
</template>

<style scoped>
/* Видимый фокус для клавиатуры: SVG-кружок иначе «невидим» при Tab. */
.seat-dot:focus {
  outline: none;
}

/*
 * Видимый кружок обязан ПРОПУСКАТЬ события.
 *
 * Прозрачная зона нажатия (`circle.seat-hit`) лежит под ним, а видимый кружок
 * рисуется поверх и по умолчанию принимает клики сам. Обработчика на нём нет —
 * поэтому реальный клик мышью или пальцем не выбирал место: событие уходило в
 * кружок и там умирало. Синтетический `dispatchEvent` по зоне работал, из-за
 * чего баг не ловился автотестами, но живой покупатель не мог ничего купить.
 *
 * Наведение при этом не страдает: его ловит родительская группа `.seat-cell`
 * (у неё в потомках зона нажатия), а эффект масштаба висит на ней же.
 */
.seat-dot {
  pointer-events: none;
}

/*
 * Масштаб при наведении считается от ЦЕНТРА кружка.
 *
 * Без `transform-box: fill-box` и `transform-origin: center` трансформ SVG-
 * элемента берёт начало от начала системы координат viewBox, и `scale(1.3)`
 * отбрасывал кружок прочь от центра — эффект наведения был не «приподнять», а
 * «убежать». С `fill-box` масштаб идёт от самой фигуры, центр не смещается.
 */
.seat-cell .seat-dot {
  transform-box: fill-box;
  transform-origin: center;
  transition: transform 120ms ease, opacity 120ms ease;
}

.seat-cell:hover .seat-dot--live {
  transform: scale(1.3);
}

/* Места не из активной ценовой категории: видны, но не участвуют. */
.seat-dot--dim {
  opacity: 0.22;
}

.seat-dot--off {
  cursor: not-allowed;
}
</style>
