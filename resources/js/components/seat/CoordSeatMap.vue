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
 */
import { computed, ref } from 'vue'
import { money, plural } from '@/lib/format'
import type { InventoryItem } from '@/lib/inventory'
import { seatStateFromStatus, isSeatPickable } from '@/lib/seatStatus'
import { buildSeatPricePalette, groupTableSeatPoints, type TableMark } from '@/lib/hall'
import { fitPoints, fitSeatRadius, projectPoint } from '@/lib/seatViewport'
import { DANCE_BOX_HEIGHT, danceZoneLayout } from '@/lib/danceZone'

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
const stage = { x: (SVG_W - 200) / 2, y: 12, w: 200, h: 30 }

/** Места (seat) с координатами. */
const seats = computed(() =>
  props.inventory.filter((i) => i.type === 'seat' && i.seat),
)

const priceColors = computed(() => buildSeatPricePalette(seats.value.map((item) => Number(item.price_amount ?? 0))))
const priceLegend = computed(() => [...priceColors.value].map(([price, color]) => ({ price, color })))

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
      reservedTop: STAGE_H,
      // Нижняя полоса отдана зоне танцпола (и пикеру количества, когда он
      // раскрыт) — иначе места ложатся на зону.
      reservedBottom: dance.value
        ? DANCE_BOX_HEIGHT + DANCE_GAP + (dancePickerOpen.value ? DANCE_PICKER_RESERVE : 0)
        : 0,
    },
  ),
)
const seatRadius = computed(() => fitSeatRadius(
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

/** Стоячая зона (танцпол). */
const dance = computed(() => props.inventory.find((i) => i.type === 'standing'))

/**
 * Строки подписи зоны танцпола. Цена и остаток — РАЗНЫЕ строки: раньше они
 * были склеены в одну («5 000 ₽ · 150 билетов»), и её ширина росла с
 * разрядностью чисел, вылезая за жёсткую рамку 150×90.
 */
const danceText = computed(() => {
  const d = dance.value
  if (!d) return null
  const count = Number(d.available_quantity ?? 0)
  return {
    title: 'Танцпол',
    price: money(d.price_amount),
    count: `${count} ${plural(count, 'билет', 'билета', 'билетов')}`,
    hint: danceSelected.value ? 'выбрано — клик, чтобы убрать' : 'клик — выбрать билеты',
  }
})

/**
 * Рамка зоны танцпола: размер считается по тексту (см. lib/danceZone.ts), а не
 * задан константой, поэтому подпись всегда помещается внутрь.
 */
const danceRect = computed(() => {
  const text = danceText.value
  if (!text) return null
  // Зона стоит в зарезервированной нижней полосе (см. `fit`), поэтому места
  // её не перекрывают.
  return danceZoneLayout(text, { canvasWidth: SVG_W, y: SVG_H - PAD - DANCE_BOX_HEIGHT })
})

const danceSelected = computed(() => {
  const d = dance.value
  return d !== undefined && selectedIds.value.has(String(d.id))
})
/** Подсветка последнего выбранного количества — локальное состояние пикера. */
const danceQty = ref(2)
const dancePickerOpen = ref(false)

/**
 * Сколько билетов можно предложить в пикере.
 *
 * Ограничивают три вещи: свободный остаток зоны (`available_quantity`),
 * остаток лимита на заказ и разумный максимум интерфейса (5).
 */
const danceOptions = computed<number[]>(() => {
  const d = dance.value
  if (!d) return []
  const byCapacity = Number(d.available_quantity)
  const room = maxTickets.value - selectedTickets.value
  const cap = Math.min(5, Number.isFinite(byCapacity) ? byCapacity : 5, room)
  return Array.from({ length: Math.max(0, Math.floor(cap)) }, (_, i) => i + 1)
})

function toggleDance(): void {
  const d = dance.value
  if (!d) return
  // Повторный клик по уже выбранной зоне — снятие. Раньше здесь стоял
  // `return`: зона оставалась выбранной навсегда, и после удаления её из
  // сводки повторно выбрать танцпол было уже нельзя до перезагрузки.
  if (danceSelected.value) {
    emit('toggle', d, 1)
    return
  }
  if (selectedTickets.value >= maxTickets.value) {
    emit('limit')
    return
  }
  dancePickerOpen.value = true
}

function pickDance(qty: number): void {
  const d = dance.value
  if (!d) return
  // Лимит проверяем ДО отправки: сервер откажет 422-м на quantity > 10, и
  // покупатель увидит техническую ошибку вместо понятного «не больше 10».
  if (selectedTickets.value + qty > maxTickets.value) {
    emit('limit')
    return
  }
  danceQty.value = qty
  dancePickerOpen.value = false
  emit('toggle', d, qty)
}

/* ── Масштаб и панорама ─────────────────────────────────────────────────
 * `transform-origin: top left` (как в SeatMap): при таком origin математика
 * зума и панорамы линейна и не зависит от размеров вьюпорта.
 */
const MIN_ZOOM = 0.6
const MAX_ZOOM = 3

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

function isPickable(item: InventoryItem): boolean {
  return isSeatPickable(item.status, item.available_quantity) || selectedIds.value.has(String(item.id))
}
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-line bg-surface-2">
    <div class="flex flex-col gap-2 border-b border-line px-3 py-2 text-2xs text-subtle">
      <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span>{{ seats.length }} мест · танцпол {{ dance?.available_quantity ?? 0 }} билетов</span>
        <span class="text-layer flex flex-wrap gap-x-3 gap-y-1">
          <span v-for="item in priceLegend" :key="item.price" class="flex items-center gap-1.5">
            <i class="inline-block size-2.5 rounded-full" :style="{ backgroundColor: item.color }" /> {{ money(item.price) }}
          </span>
        </span>
      </div>
      <div class="flex flex-wrap gap-x-3 gap-y-1 text-layer">
        <span v-for="l in LEGEND_ITEMS" :key="l.state" class="flex items-center gap-1.5">
          <i class="inline-block size-2.5 rounded-full" :style="{ background: STATE_FILL[l.state] }" /> {{ l.label }}
        </span>
      </div>
    </div>

    <div class="relative">
      <!-- Масштаб: паритет с SeatMap (там кнопки есть, здесь их не было). -->
      <div class="absolute right-3 top-3 z-20 flex flex-col gap-1.5">
        <button
          type="button"
          class="grid h-8 w-8 place-items-center rounded-md border border-line bg-surface text-sm text-muted shadow-sm transition-colors hover:text-content"
          aria-label="Приблизить"
          @click="zoomBy(1.25)"
        >+</button>
        <button
          type="button"
          class="grid h-8 w-8 place-items-center rounded-md border border-line bg-surface text-sm text-muted shadow-sm transition-colors hover:text-content"
          aria-label="Отдалить"
          @click="zoomBy(0.8)"
        >−</button>
        <button
          type="button"
          class="grid h-8 w-8 place-items-center rounded-md border border-line bg-surface text-2xs text-muted shadow-sm transition-colors hover:text-content"
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

          <!-- Танцпол -->
          <g v-if="dance && danceRect">
            <rect
              :x="danceRect.x" :y="danceRect.y" :width="danceRect.w" :height="danceRect.h"
              rx="14" fill="rgba(240,180,60,0.12)" :stroke="danceSelected ? '#C9A0FF' : '#E8B544'" stroke-width="2" stroke-dasharray="8 5"
              class="cursor-pointer transition hover:opacity-80"
              role="button"
              tabindex="0"
              :aria-label="danceSelected ? 'Танцпол выбран, нажмите чтобы убрать' : 'Танцпол: выбрать количество билетов'"
              :aria-pressed="danceSelected"
              @click="toggleDance"
              @keydown.enter.prevent="toggleDance"
              @keydown.space.prevent="toggleDance"
            />
            <text :x="SVG_W / 2" :y="danceRect.titleY" text-anchor="middle" fill="#596273" font-size="14" font-weight="700">{{ danceText?.title }}</text>
            <text :x="SVG_W / 2" :y="danceRect.priceY" text-anchor="middle" fill="#596273" font-size="12">{{ danceText?.price }}</text>
            <text :x="SVG_W / 2" :y="danceRect.countY" text-anchor="middle" fill="#596273" font-size="12">{{ danceText?.count }}</text>
            <text
              v-if="!dancePickerOpen"
              :x="SVG_W / 2" :y="danceRect.hintY" text-anchor="middle"
              :fill="danceSelected ? '#5B43C6' : '#667181'" font-size="11"
            >{{ danceText?.hint }}</text>

            <!-- Селектор количества билетов -->
            <!--
              Пикер раскрывается НАД зоной: под зоной край полотна, и раньше
              он уезжал за него (`danceRect.y + danceRect.h + 20` = 390 при
              высоте 400 — половина кружков обрезалась).
            -->
            <g v-if="dancePickerOpen" :transform="`translate(${SVG_W / 2 - 110}, ${danceRect.y - 26})`">
              <rect x="0" y="-18" width="220" height="44" rx="12" fill="#1a1530" stroke="#F0C060" stroke-width="1" />
              <g v-for="(q, i) in danceOptions" :key="q" @click="pickDance(q)" class="cursor-pointer">
                <circle :cx="15 + i * 42" cy="4" :r="14" :fill="q === danceQty ? '#F0C060' : '#2a2140'" stroke="#E8B544" stroke-width="1">
                  <title>Купить {{ q }} {{ plural(q, 'билет', 'билета', 'билетов') }}</title>
                </circle>
                <text :x="15 + i * 42" :y="8" text-anchor="middle" fill="#1a1530" font-size="13" font-weight="700">{{ q }}</text>
              </g>
              <text v-if="danceOptions.length === 0" x="110" y="8" text-anchor="middle" fill="#F0C060" font-size="12">свободных билетов нет</text>
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
          <g v-for="s in seats" :key="String(s.id)">
            <circle
              :cx="px(s).x" :cy="px(s).y" :r="seatRadius"
              :fill="seatFill(s)"
              :stroke="focusedId === String(s.id) ? '#ffffff' : 'none'"
              :stroke-width="focusedId === String(s.id) ? 2.5 : 0"
              :class="isPickable(s) ? 'seat-dot cursor-pointer transition hover:scale-125' : 'seat-dot cursor-not-allowed'"
              role="button"
              tabindex="0"
              :aria-label="seatTitle(s)"
              :aria-pressed="selectedIds.has(String(s.id))"
              @click="toggleSeat(s)"
              @keydown="activateByKey($event, s)"
              @focus="focusedId = String(s.id)"
              @blur="focusedId = null"
            >
              <title>{{ seatTitle(s) }}</title>
            </circle>
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

      <p class="border-t border-line px-3 py-1.5 text-2xs text-subtle">
        Перетаскивание и щипок — приблизить зал. Кнопки справа — масштаб.
      </p>
    </div>
  </div>
</template>

<style scoped>
/* Видимый фокус для клавиатуры: SVG-кружок иначе «невидим» при Tab. */
.seat-dot:focus {
  outline: none;
}
</style>
