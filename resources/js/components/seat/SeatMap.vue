<script setup lang="ts">
/**
 * Карта зала.
 *
 * Решения, продиктованные привычками пользователя, а не вкусом:
 *
 * 1. Места — настоящие <button>, а не кружки в SVG. Значит, работают клавиатура,
 *    скринридер и тач-скролл, а подсказка браузера объясняет, что место занято.
 * 2. Ориентир — сцена сверху. Пользователь держит в голове «ближе к сцене =
 *    дороже», карта обязана совпадать с этой моделью, иначе выбор кажется
 *    случайным.
 * 3. Стрелки двигают фокус по ряду и между рядами. Выбирать 4 места подряд
 *    мышкой — мучение; клавиатурой — два нажатия.
 * 4. Занятое место не «серое»: проданное, придержанное и недоступное — три
 *    разных вида. Иначе пользователь решает, что зал пустой, и сердится.
 * 5. Масштаб и перетаскивание: зал целиком виден на телефоне, но приближение
 *    доступно — номер места читается только вблизи.
 */
import { computed, ref } from 'vue'
import {
  SEAT_GEOMETRY,
  buildHall,
  rowWidth,
  sectorHeight,
  seatLeft,
  seatTop,
  type Row,
  type Seat,
  type Sector,
} from '@/lib/hall'
import { cn } from '@/lib/cn'
import { money } from '@/lib/format'

const props = withDefaults(
  defineProps<{
    /** Идентификаторы выбранных мест. Источник истины — родитель (стор корзины). */
    selected: string[]
    maxSelection?: number
    /** Схема зала. Если не передана — демо-зал. */
    sectors?: Sector[]
  }>(),
  { maxSelection: 10 },
)

const emit = defineEmits<{ toggle: [seat: Seat, sectorName: string]; limit: [] }>()

const hall = computed<Sector[]>(() => props.sectors ?? buildHall())

const STAGE_HEIGHT = 44
const HEADER_ROOM = 56
const { SEAT_SIZE, ROW_LABEL_WIDTH, SECTOR_GAP } = SEAT_GEOMETRY

/* ── Геометрия: считается один раз, в одном месте ───────────────────── */

interface PlacedSeat {
  seat: Seat
  sectorName: string
  x: number
  y: number
}

interface SectorBox {
  sector: Sector
  top: number
  height: number
  width: number
}

const sectorBoxes = computed<SectorBox[]>(() => {
  let cursor = STAGE_HEIGHT + HEADER_ROOM
  return hall.value.map((sector) => {
    const box: SectorBox = {
      sector,
      top: cursor,
      height: sectorHeight(sector),
      width: Math.max(...sector.rows.map(rowWidth)),
    }
    cursor += box.height + SECTOR_GAP + 34
    return box
  })
})

const mapWidth = computed(() => Math.max(...sectorBoxes.value.map((b) => b.width)) + ROW_LABEL_WIDTH * 2)
const mapHeight = computed(() => {
  const last = sectorBoxes.value[sectorBoxes.value.length - 1]
  return last ? last.top + last.height + 40 : 0
})

const placed = computed<PlacedSeat[]>(() =>
  sectorBoxes.value.flatMap((box) =>
    box.sector.rows.flatMap((row: Row) =>
      row.seats.map((seat, i) => ({
        seat,
        sectorName: box.sector.name,
        x: seatLeft(row, i),
        y: box.top + seatTop(row.index),
      })),
    ),
  ),
)

/* ── Масштаб и панорама ─────────────────────────────────────────────── */

const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const MIN_ZOOM = 0.5
const MAX_ZOOM = 2.2
const dragging = ref(false)

function zoomBy(factor: number): void {
  zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number((zoom.value * factor).toFixed(2))))
}

function resetView(): void {
  zoom.value = 1
  panX.value = 0
  panY.value = 0
}

let dragFrom: { x: number; y: number; px: number; py: number } | null = null

function onPointerDown(event: PointerEvent): void {
  dragFrom = { x: event.clientX, y: event.clientY, px: panX.value, py: panY.value }
  dragging.value = true
}

function onPointerMove(event: PointerEvent): void {
  if (!dragFrom) return
  panX.value = dragFrom.px + (event.clientX - dragFrom.x)
  panY.value = dragFrom.py + (event.clientY - dragFrom.y)
}

function onPointerUp(): void {
  dragFrom = null
  dragging.value = false
}

/* ── Состояние места ────────────────────────────────────────────────── */

const selectedSet = computed(() => new Set(props.selected))

function visualState(seat: Seat): string {
  if (selectedSet.value.has(seat.id)) return 'seat--selected'
  if (seat.state === 'held') return 'seat--held'
  if (seat.state === 'sold') return 'seat--sold'
  if (seat.state === 'unavailable') return 'seat--unavailable'
  if (seat.kind === 'vip') return 'seat--vip'
  if (seat.kind === 'accessible') return 'seat--accessible'
  return ''
}

/** Проданное и придержанное место нельзя выбрать — сервер всё равно откажет. */
function isPickable(seat: Seat): boolean {
  return seat.state === 'free'
}

function pick(p: PlacedSeat): void {
  if (!isPickable(p.seat)) return
  if (!selectedSet.value.has(p.seat.id) && props.selected.length >= props.maxSelection) {
    emit('limit')
    return
  }
  emit('toggle', p.seat, p.sectorName)
}

function ariaLabel(p: PlacedSeat): string {
  const base = `${p.sectorName}, ряд ${p.seat.row}, место ${p.seat.number}`
  const price = money(p.seat.priceMinor)
  if (selectedSet.value.has(p.seat.id)) return `${base}, ${price} — выбрано, нажмите чтобы убрать`
  if (p.seat.state === 'sold') return `${base} — продано`
  if (p.seat.state === 'held') return `${base} — придержано другим покупателем`
  if (p.seat.state === 'unavailable') return `${base} — недоступно`
  if (p.seat.kind === 'vip') return `${base}, ${price} — VIP, свободно`
  if (p.seat.kind === 'accessible') return `${base}, ${price} — для маломобильных, свободно`
  return `${base}, ${price} — свободно`
}

/* ── Навигация стрелками ────────────────────────────────────────────── */

const focusedIndex = ref(-1)

function focusSeat(index: number): void {
  if (index < 0 || index >= placed.value.length) return
  focusedIndex.value = index
  const el = document.getElementById(`seat-${placed.value[index].seat.id}`)
  el?.focus()
}

/** «Вправо/влево» — соседнее место, «вверх/вниз» — соседний ряд. */
function moveFocus(from: number, dx: number, dy: number): void {
  const start = placed.value[from]
  if (!start) return

  if (dy === 0) {
    focusSeat(Math.min(placed.value.length - 1, Math.max(0, from + dx)))
    return
  }

  const targetRow = start.seat.row + dy
  const sameRow = placed.value
    .map((p, i) => ({ p, i }))
    .filter(({ p }) => p.seat.row === targetRow && p.sectorName === start.sectorName)

  if (sameRow.length === 0) return

  // Ряды разной длины — берём ближайшее место по номеру.
  const nearest = sameRow.reduce((best, cur) =>
    Math.abs(cur.p.seat.number - start.seat.number) < Math.abs(best.p.seat.number - start.seat.number) ? cur : best,
  )
  focusSeat(nearest.i)
}

function onSeatKeydown(event: KeyboardEvent, index: number): void {
  const map: Record<string, [number, number]> = {
    ArrowRight: [1, 0],
    ArrowLeft: [-1, 0],
    ArrowUp: [0, -1],
    ArrowDown: [0, 1],
  }
  const delta = map[event.key]
  if (!delta) return
  event.preventDefault()
  moveFocus(index, delta[0], delta[1])
}
</script>

<template>
  <div class="relative overflow-hidden rounded-xl border border-line bg-surface-2">
    <!-- Подсветка сцены: якорь «верх = сцена» -->
    <div class="pointer-events-none absolute inset-x-0 top-0 h-40 bg-stage" aria-hidden="true" />

    <!-- Масштаб -->
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
      class="relative touch-none overflow-hidden"
      :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
      style="height: min(60vh, 620px)"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
    >
      <div
        class="relative origin-top-left select-none transition-transform duration-120 ease-out"
        :style="{
          width: `${mapWidth}px`,
          height: `${mapHeight}px`,
          transform: `translate(${panX}px, ${panY}px) scale(${zoom})`,
        }"
      >
        <!-- Сцена -->
        <div
          class="stage-bar absolute left-1/2 top-6 flex h-11 w-64 -translate-x-1/2 items-center justify-center rounded-b-2xl rounded-t-md text-sm font-semibold uppercase tracking-[0.2em] text-white"
        >
          Сцена
        </div>

        <!-- Заголовки секторов -->
        <div
          v-for="box in sectorBoxes"
          :key="`head-${box.sector.id}`"
          class="absolute flex items-baseline gap-2"
          :style="{ left: `${ROW_LABEL_WIDTH}px`, top: `${box.top - 26}px` }"
        >
          <span class="text-xs font-semibold uppercase tracking-wide text-brand-400">{{ box.sector.name }}</span>
          <span class="text-2xs text-subtle">от {{ money(box.sector.priceMinor) }}</span>
        </div>

        <!-- Номера рядов -->
        <template v-for="box in sectorBoxes" :key="`rows-${box.sector.id}`">
          <span
            v-for="row in box.sector.rows"
            :key="`${box.sector.id}-r${row.index}`"
            class="absolute w-6 text-right text-2xs tabular-nums text-subtle"
            :style="{ left: '0px', top: `${box.top + seatTop(row.index)}px`, lineHeight: `${SEAT_SIZE}px` }"
            aria-hidden="true"
          >{{ row.index }}</span>
        </template>

        <!-- Места -->
        <button
          v-for="(p, index) in placed"
          :id="`seat-${p.seat.id}`"
          :key="p.seat.id"
          type="button"
          :class="cn('seat absolute', visualState(p.seat), !isPickable(p.seat) && 'pointer-events-none')"
          :style="{
            left: `${p.x}px`,
            top: `${p.y}px`,
            width: `${SEAT_SIZE}px`,
            height: `${SEAT_SIZE}px`,
          }"
          :aria-label="ariaLabel(p)"
          :aria-pressed="selectedSet.has(p.seat.id)"
          :tabindex="focusedIndex === index || (focusedIndex === -1 && index === 0) ? 0 : -1"
          @click="pick(p)"
          @keydown="onSeatKeydown($event, index)"
          @focus="focusedIndex = index"
        />
      </div>
    </div>
  </div>
</template>
