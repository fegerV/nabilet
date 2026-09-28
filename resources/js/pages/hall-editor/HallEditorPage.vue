<script setup lang="ts">
/**
 * Редактор схем залов.
 *
 * Здесь работают долго и кропотливо, поэтому интерфейс подчинён одной идее:
 * ни одно состояние схемы не должно оставаться «в голове». Отсюда решения:
 *
 *  1. Версия всегда видна. Опубликованная схема неизменяема (ТЗ §6) — статус
 *     «черновик / опубликовано» это не подпись, а режим работы: в опубликованной
 *     версии инструменты редактирования физически отключаются.
 *  2. Инструменты слева, свойства справа, холст посередине — раскладка из
 *     любого графического редактора, учить интерфейс заново не надо.
 *  3. Генератор рядов, а не расстановка мест руками: зал на 400 мест рисуется
 *     тремя числами, а не четырьмястами кликов.
 *  4. Каждое изменение автосохраняется через 500 ms (ТЗ §52) и обратимо:
 *     удаление сектора через отмену, а не «ой, сейчас перерисую».
 */
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Konva from 'konva'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import { useUiStore } from '@/stores/ui'
import { money, plural } from '@/lib/format'
import { cn } from '@/lib/cn'
import { get, send } from '@/lib/api'

/* ── Типы ──────────────────────────────────────────────────────────── */

type SeatKind = 'standard' | 'vip' | 'accessible'
type Tool =
  | 'select' | 'pan' | 'zoom' | 'seat' | 'row'
  | 'sector' | 'table' | 'standing' | 'text'
  | 'image' | 'stage' | 'entrance'
type Autosave = 'saving' | 'saved' | 'error' | 'offline'
type StaticKind = 'stage' | 'entrance' | 'label' | 'table' | 'standing'

interface ESeat {
  id: string
  row: number
  number: number
  kind: SeatKind
  x: number
  y: number
}

type SectorShape = 'grid' | 'arc'

interface ESector {
  id: string
  name: string
  /** Цена по умолчанию для рядов без индивидуальной цены (минорные единицы). */
  priceMinor: number
  x: number
  y: number
  seats: ESeat[]
  /** Цена по конкретному ряду (§50): перекрывает priceMinor. */
  rowPrices: Record<number, number>
  /** Форма раскладки мест: прямоугольная сетка или амфитеатр (дуга). */
  shape: SectorShape
  /** Угол раствора дуги в градусах (только для shape === 'arc'). */
  arcSpread: number
  /** Базовый радиус первого ряда (px) — для продолжения дуги при добавлении рядов. */
  arcBaseR: number
  /** Прирост радиуса на каждый ряд (px). */
  arcRowGap: number
  /** Сдвиг дуги по X, чтобы сектор был центрирован и в положительных координатах. */
  arcOffsetX: number
  /** Сдвиг дуги по Y, чтобы верхний край был в положительных координатах. */
  arcOffsetY: number
}

interface EStatic {
  id: string
  kind: StaticKind
  x: number
  y: number
  width?: number
  height?: number
  rotation?: number
  opacity?: number
  locked?: boolean
  text?: string
  capacity?: number
}

interface EBackground {
  id: string
  src: string
  x: number
  y: number
  width: number
  height: number
  rotation: number
  locked: boolean
  opacity: number
}

/* ── Константы ─────────────────────────────────────────────────────── */

const SEAT = 16
const GAP = 6
const ROW_GAP = 12

const ui = useUiStore()

/* ── Инструменты (§47) ─────────────────────────────────────────────── */

const TOOLS: { value: Tool; label: string; icon: string; hint: string }[] = [
  { value: 'select', label: 'Выделение', icon: '↖', hint: 'Клик или рамка — выделить' },
  { value: 'pan', label: 'Панорама', icon: '✋', hint: 'Тяните холст, чтобы двигать вид' },
  { value: 'zoom', label: 'Масштаб', icon: '🔍', hint: 'Клик приближает, Alt+клик — отдаляет' },
  { value: 'seat', label: 'Место', icon: '▪', hint: 'Клик в секторе — добавить место' },
  { value: 'row', label: 'Ряд', icon: '▤', hint: 'Добавить ряд к выбранному сектору' },
  { value: 'sector', label: 'Сектор', icon: '▣', hint: 'Тяните прямоугольник — создать сектор' },
  { value: 'table', label: 'Стол', icon: '◫', hint: 'Клик — поставить стол (VIP-зона)' },
  { value: 'standing', label: 'Standing', icon: '▦', hint: 'Тяните прямоугольник — стоячая зона' },
  { value: 'text', label: 'Текст', icon: 'T', hint: 'Клик — поставить подпись' },
  { value: 'image', label: 'Фон', icon: '🖼', hint: 'Загрузить PNG/JPG/WEBP/SVG под схемой' },
  { value: 'stage', label: 'Сцена', icon: '▬', hint: 'Клик — переместить сцену' },
  { value: 'entrance', label: 'Вход', icon: '↗', hint: 'Клик — поставить вход' },
]

/* ── Состояние схемы ───────────────────────────────────────────────── */

const sectors = ref<ESector[]>([])
const statics = ref<EStatic[]>([])
const backgrounds = ref<EBackground[]>([])

const selectedSectorId = ref<string | null>(null)
const selectedSeatIds = ref<Set<string>>(new Set())
/** Статика выделяется мультивыбором (группа + рамка), как места (§48). */
const selectedStaticIds = ref<Set<string>>(new Set())
/** Выделение фоновых изображений: клик по фону → панель свойств (§51). */
const selectedBgIds = ref<Set<string>>(new Set())

const published = ref<number | null>(null)
const draftVersion = ref(1)
const autosave = ref<Autosave>('saved')
const lastSavedAt = ref<number>(Date.now())

/* ── API-подключение: зал по publicId из роута ────────────────────── */

const route = useRoute()
/** publicId зала берём из пути /admin/halls/:publicId/editor. */
const hallPublicId = ref<string | null>(null)
/** id черновика на сервере (null — ещё не создан). */
const draftVersionId = ref<number | null>(null)
/** Текущий published id (для отображения номера версии). */
const loadedPublished = ref<number | null>(null)
const hallLoadState = ref<'idle' | 'loading' | 'ok' | 'error'>('idle')

/** Реальный id версии (для publish). */
let currentVersionId: number | null = null

const tool = ref<Tool>('select')

/* Полоски панелей: по умолчанию скрыты на узких экранах — канвасу максимум места. */
const showTools = ref(typeof window !== 'undefined' && window.innerWidth >= 1500)
const showInspector = ref(typeof window !== 'undefined' && window.innerWidth >= 1500)

/* Форма нового сектора (§49): сектор / форма / ряд / кол-во мест / шаг. */
const form = ref({
  name: 'Партер A',
  shape: 'grid' as SectorShape,
  rows: 8,
  seatsPerRow: 18,
  priceMinor: 850000,
  vipRows: 2,
  arcSpread: 160,
})

const history = ref<SchemaSnapshot[]>([])
const future = ref<SchemaSnapshot[]>([])

let idCounter = 0
const nextId = (prefix: string): string => `${prefix}-${Date.now().toString(36)}-${(idCounter += 1)}`

interface SchemaSnapshot {
  sectors: ESector[]
  statics: EStatic[]
  backgrounds: EBackground[]
}

/**
 * Снапшот ДО изменения. Раньше snapshot() вызывался после мутации — undo
 * возвращал уже изменённое состояние, а первое действие было не отменить.
 */
function snapshot(): void {
  history.value.push({
    sectors: JSON.parse(JSON.stringify(sectors.value)) as ESector[],
    statics: JSON.parse(JSON.stringify(statics.value)) as EStatic[],
    backgrounds: JSON.parse(JSON.stringify(backgrounds.value)) as EBackground[],
  })
  future.value = []
  if (history.value.length > 50) history.value.shift()
}

/**
 * Перемещение группы (сектор/статика) Konva мутирует модель напрямую и не
 * проходит через snapshot(). Отдельный флаг: снапшот «до» берётся один раз
 * на начало drag, финализация — на dragend (см. beginMoveSnapshot).
 */
let moveSnapshotTaken = false
let snapshotPendingMove = false
function beginMoveSnapshot(): void {
  if (!moveSnapshotTaken) {
    snapshot()
    moveSnapshotTaken = true
  }
}

function undo(): void {
  const prev = history.value.pop()
  if (!prev) return
  future.value.push({
    sectors: JSON.parse(JSON.stringify(sectors.value)) as ESector[],
    statics: JSON.parse(JSON.stringify(statics.value)) as EStatic[],
    backgrounds: JSON.parse(JSON.stringify(backgrounds.value)) as EBackground[],
  })
  sectors.value = prev.sectors
  statics.value = prev.statics
  backgrounds.value = prev.backgrounds
  selectedSectorId.value = null
  selectedSeatIds.value = new Set()
  selectedStaticIds.value = new Set()
  selectedBgIds.value = new Set()
}

function redo(): void {
  const next = future.value.pop()
  if (!next) return
  history.value.push({
    sectors: JSON.parse(JSON.stringify(sectors.value)) as ESector[],
    statics: JSON.parse(JSON.stringify(statics.value)) as EStatic[],
    backgrounds: JSON.parse(JSON.stringify(backgrounds.value)) as EBackground[],
  })
  sectors.value = next.sectors
  statics.value = next.statics
  backgrounds.value = next.backgrounds
}

/* ── Генератор рядов (§49) ─────────────────────────────────────────── */

/** Координата места на дуге (амфитеатр). Центр кривизны — в (arcOffsetX, arcOffsetY). */
function arcSeatXY(sector: ESector, r: number, n: number, seatsPerRow: number): { x: number; y: number } {
  const theta = (sector.arcSpread * Math.PI) / 180
  const R = sector.arcBaseR + r * sector.arcRowGap
  const a = seatsPerRow > 1 ? -theta / 2 + (n * theta) / (seatsPerRow - 1) : 0
  return { x: R * Math.sin(a) + sector.arcOffsetX, y: R * Math.cos(a) + sector.arcOffsetY }
}

function generateSector(): void {
  snapshot()
  const { name, shape, rows, seatsPerRow, priceMinor, vipRows, arcSpread } = form.value
  const sector: ESector = {
    id: nextId('sec'),
    name,
    priceMinor,
    x: 80 + sectors.value.length * 40,
    y: 140 + sectors.value.length * 12,
    seats: [],
    rowPrices: {},
    shape,
    arcSpread,
    arcBaseR: 0,
    arcRowGap: 0,
    arcOffsetX: 0,
    arcOffsetY: 0,
  }

  if (shape === 'arc') {
    // Раскладываем места по концентрическим дугам: равный шаг вдоль дуги,
    // радиус растёт на каждый ряд. Центр кривизны — внизу под залом,
    // поэтому ряды «смотрят» выпуклостью к сцене (вверх).
    const theta = (arcSpread * Math.PI) / 180
    const rowGap = SEAT + ROW_GAP
    const R0 = seatsPerRow > 1 ? ((seatsPerRow - 1) * (SEAT + GAP)) / theta : 60
    const maxR = R0 + (rows - 1) * rowGap
    sector.arcBaseR = R0
    sector.arcRowGap = rowGap
    sector.arcOffsetX = maxR * Math.sin(theta / 2)
    sector.arcOffsetY = -R0 * Math.cos(theta / 2)
    for (let r = 0; r < rows; r += 1) {
      for (let n = 0; n < seatsPerRow; n += 1) {
        const kind: SeatKind = r < vipRows ? 'vip' : n === 0 || n === seatsPerRow - 1 ? 'accessible' : 'standard'
        const { x, y } = arcSeatXY(sector, r, n, seatsPerRow)
        sector.seats.push({ id: nextId('seat'), row: r + 1, number: n + 1, kind, x, y })
      }
    }
  } else {
    for (let r = 0; r < rows; r += 1) {
      for (let n = 0; n < seatsPerRow; n += 1) {
        const kind: SeatKind = r < vipRows ? 'vip' : n === 0 || n === seatsPerRow - 1 ? 'accessible' : 'standard'
        sector.seats.push({
          id: nextId('seat'),
          row: r + 1,
          number: n + 1,
          kind,
          x: n * (SEAT + GAP),
          y: r * (SEAT + ROW_GAP),
        })
      }
    }
  }

  sectors.value.push(sector)
  selectedSectorId.value = sector.id
  ui.notify('mint', 'Сектор создан', `${sector.seats.length} мест, ${rows} рядов${shape === 'arc' ? ', амфитеатр' : ''}`)
}

function addRowToSelected(): void {
  // Инструмент «ряд» (§47): либо выбран сам инструмент, либо кнопка «+ Ряд» в
  // инспекторе — иначе клик по кнопке молча игнорировался (дефект A4).
  if (tool.value !== 'row' && tool.value !== 'select') {
    ui.notify('sun', 'Не тот инструмент', 'Кнопка «+ Ряд» работает с инструментами «Ряд» или «Выделение»')
    return
  }
  const sector = sectors.value.find((s) => s.id === selectedSectorId.value)
  if (!sector) {
    ui.notify('rose', 'Сначала выберите сектор', 'Кликните по сектору на холсте')
    return
  }
  snapshot()
  const nextRow = (sector.seats.reduce((m, s) => Math.max(m, s.row), 0) || 0) + 1
  const sample = sector.seats[0]
  const seatsPerRow = sample ? sector.seats.filter((s) => s.row === sample.row).length : form.value.seatsPerRow

  if (sector.shape === 'arc') {
    // Продолжаем дугу: пересчитываем центровку по X и сдвигаем старые места,
    // чтобы новый (самый широкий) ряд остался симметричным.
    const theta = (sector.arcSpread * Math.PI) / 180
    const newMaxR = sector.arcBaseR + (nextRow - 1) * sector.arcRowGap
    const newOffsetX = newMaxR * Math.sin(theta / 2)
    const dx = newOffsetX - sector.arcOffsetX
    if (dx) for (const seat of sector.seats) seat.x += dx
    sector.arcOffsetX = newOffsetX
    const r = nextRow - 1
    for (let n = 0; n < seatsPerRow; n += 1) {
      const { x, y } = arcSeatXY(sector, r, n, seatsPerRow)
      sector.seats.push({ id: nextId('seat'), row: nextRow, number: n + 1, kind: 'standard', x, y })
    }
  } else {
    for (let n = 0; n < seatsPerRow; n += 1) {
      sector.seats.push({
        id: nextId('seat'),
        row: nextRow,
        number: n + 1,
        kind: 'standard',
        x: n * (SEAT + GAP),
        y: (nextRow - 1) * (SEAT + ROW_GAP),
      })
    }
  }
  ui.notify('mint', `Ряд ${nextRow} добавлен`, `${seatsPerRow} мест${sector.shape === 'arc' ? ', по дуге' : ''}`)
}

/* ── Операции с местами (§48) ──────────────────────────────────────── */

/**
 * Инструменты правки — всё, кроме навигации (select/pan/zoom).
 * Каждый инструмент из списка обязан ЧТО-ТО делать на холсте (§47):
 *   seat/row/sector/table/standing/text/image/stage/entrance — см. обработчики
 *   ниже (handleToolClick, onStageMouseDown/Move/Up, triggerImageUpload).
 */
const EDIT_TOOLS: Tool[] = ['seat', 'row', 'sector', 'table', 'standing', 'text', 'image', 'stage', 'entrance']

function pickSeat(seatId: string, sectorId: string, additive: boolean): void {
  if (isLocked.value) return
  // Мультиселект работает и Shift-ом, и Ctrl/Cmd-ом (§48).
  if (additive) {
    const next = new Set(selectedSeatIds.value)
    if (next.has(seatId)) next.delete(seatId)
    else next.add(seatId)
    selectedSeatIds.value = next
    if (next.size > 0) selectedSectorId.value = sectorId
  } else {
    selectedSectorId.value = sectorId
    selectedSeatIds.value = new Set([seatId])
    selectedStaticIds.value = new Set()
  }
}

/** Выделение статического объекта (клик по группе на холсте, §48). */
function pickStatic(staticId: string, additive: boolean): void {
  if (isLocked.value) return
  if (additive) {
    const next = new Set(selectedStaticIds.value)
    if (next.has(staticId)) next.delete(staticId)
    else next.add(staticId)
    selectedStaticIds.value = next
  } else {
    selectedStaticIds.value = new Set([staticId])
    selectedSeatIds.value = new Set()
    selectedSectorId.value = null
  }
}

/** Выделить/снять фон (клик по изображению, §51). Панель свойств — одна запись. */
function pickBackground(bgId: string): void {
  if (isLocked.value) return
  selectedBgIds.value = new Set([bgId])
  selectedSeatIds.value = new Set()
  selectedStaticIds.value = new Set()
  selectedSectorId.value = null
}

/* ── Инструменты создания (§47): seat / row / sector / table / standing / text / image / stage / entrance ── */

/** Перевести экранные координаты клика в координаты холста (с учётом pan/zoom). */
function toCanvasPoint(clientX: number, clientY: number): { x: number; y: number } | null {
  if (!stage) return null
  const rect = stage.container().getBoundingClientRect()
  const scale = stage.scaleX() || 1
  return {
    x: Math.round((clientX - rect.left - stage.x()) / scale),
    y: Math.round((clientY - rect.top - stage.y()) / scale),
  }
}

/** Сектор, в прямоугольник которого попадает точка холста (или null). */
function findSectorAt(x: number, y: number): ESector | null {
  for (const sector of sectors.value) {
    const bb = bbox(sector)
    if (x >= sector.x + bb.x - 8 && x <= sector.x + bb.x + bb.width + 8
      && y >= sector.y + bb.y - 24 && y <= sector.y + bb.y + bb.height + 8) return sector
  }
  return null
}

/** Ближайший сектор к точке (для инструмента «место»), или null. */
function nearestSector(x: number, y: number): ESector | null {
  let best: ESector | null = null
  let bestDist = Infinity
  for (const sector of sectors.value) {
    if (sector.seats.length === 0) continue
    const bb = bbox(sector)
    const cx = sector.x + bb.x + bb.width / 2
    const cy = sector.y + bb.y + bb.height / 2
    const dist = Math.hypot(x - cx, y - cy)
    if (dist < bestDist) { bestDist = dist; best = sector }
  }
  return best
}

/**
 * Сектор и ближайшая к точке существующая позиция места (в координатах
 * сектора). Используется для «щелчка по занятому месту»: новое место
 * приставляется к соседней свободной позиции, а не ставится в точку клика
 * поверх существующего квадрата.
 */
function nearestSeatAnchor(sector: ESector, lx: number, ly: number): { row: number; x: number; y: number } {
  let best: ESeat | null = null
  let bestDist = Infinity
  for (const seat of sector.seats) {
    const d = Math.hypot(lx - (seat.x + SEAT / 2), ly - (seat.y + SEAT / 2))
    if (d < bestDist) { bestDist = d; best = seat }
  }
  if (!best) return { row: 1, x: lx, y: ly }
  return { row: best.row, x: best.x, y: best.y }
}

/** Добавить одиночное место кликом (инструмент seat, §47). */
function addSeatAt(x: number, y: number): void {
  // Приоритет: сектор под курсором → выбранный сектор → ближайший.
  const target = findSectorAt(x, y) ?? selectedSector.value ?? nearestSector(x, y)
  if (!target) {
    ui.notify('rose', 'Нет сектора', 'Место можно добавить только в существующий сектор — создайте сектор (▣)')
    return
  }
  snapshot()
  let localX = Math.round(x - target.x)
  let localY = Math.round(y - target.y)
  const rows = Array.from(new Set(target.seats.map((s) => s.row))).sort((a, b) => a - b)
  const lastRow = rows[rows.length - 1] ?? 1
  const rowStep = SEAT + ROW_GAP
  let newRow = rows.reduce(
    (best, r) => {
      const rep = target.seats.find((s) => s.row === r)
      if (!rep) return best
      return Math.abs(localY - (rep.y + SEAT / 2)) < Math.abs(localY - (best.y + SEAT / 2)) ? rep : best
    },
    target.seats.find((s) => s.row === rows[0]) ?? { y: localY } as ESeat,
  ).row
  const inLastRow = target.seats.filter((s) => s.row === lastRow)
  if (inLastRow.length > 0 && localY > inLastRow[0].y + rowStep * 0.6) newRow = lastRow + 1

  // Клик рядом с уже существующим местом (в пределах одного шага сетки) —
  // это «приставить место», а не «поставить в точку клика»: иначе два места
  // легли бы друг на друга, а перенумерация по X дала бы коллизию позиций.
  const occupiedNear = target.seats.some(
    (s) => s.row === newRow && Math.abs(s.x - localX) < SEAT && Math.abs(s.y - localY) < SEAT,
  )
  if (occupiedNear) {
    const anchor = nearestSeatAnchor(target, localX, localY)
    newRow = anchor.row
    localX = anchor.x + (SEAT + GAP)
    localY = anchor.y
  }

  const rowSeats = target.seats.filter((s) => s.row === newRow)
  const maxNumber = rowSeats.reduce((m, s) => Math.max(m, s.number), 0)
  const kind: SeatKind = 'standard'
  target.seats.push({ id: nextId('seat'), row: newRow, number: maxNumber + 1, kind, x: localX, y: localY })
  // Порядок нумерации в ряду — слева направо по X (§48).
  renumberRows(target, new Set([newRow]))
  selectedSectorId.value = target.id
  const created = target.seats[target.seats.length - 1]
  selectedSeatIds.value = new Set([created.id])
  selectedStaticIds.value = new Set()
  ui.notify('mint', 'Место добавлено', `${target.name}, ряд ${newRow}, место ${created.number}`)
}

/**
 * Пересчитать номера мест внутри каждого затронутого ряда (по X слева
 * направо). Нужен после группового перемещения: места могут пересесть в
 * другой ряд или поменять порядок — нумерация обязана остаться читаемой (§48).
 */
function renumberRows(sector: ESector, rowsTouched: Set<number>): void {
  if (rowsTouched.size === 0) return
  for (const row of rowsTouched) {
    const inRow = sector.seats
      .filter((s) => s.row === row)
      .sort((a, b) => a.x - b.x)
    inRow.forEach((seat, i) => { seat.number = i + 1 })
  }
}

/** Ряд сектора для глобальной Y-координаты места (ближайший по вертикали). */
function rowForGlobalY(sector: ESector, gy: number): number {
  const rows = Array.from(new Set(sector.seats.map((s) => s.row))).sort((a, b) => a - b)
  let best = rows[0] ?? 1
  let bestDist = Infinity
  for (const r of rows) {
    const rep = sector.seats.find((s) => s.row === r)
    if (!rep) continue
    const d = Math.abs(gy - (rep.y + SEAT / 2))
    if (d < bestDist) { bestDist = d; best = r }
  }
  return best
}

/* ── Перенумерация рядов при перемещении мест (§48) ────────────────── */

/** id мест, которые можно тянуть мышью (выделены и не заняты drag'ом секции). */
let seatDragIds: Set<string> = new Set()
/** Позиции мест до начала drag: id → {sectorId, row, x, y}. */
const seatDragBefore = new Map<string, { sectorId: string; row: number; x: number; y: number }>()

/**
 * Найти место под курсором среди ВЫДЕЛЕННЫХ. Konva выдаёт события rect'а
 * внутри draggable-группы сектора, поэтому координаты клика переводим в
 * локальные (с учётом pan/zoom и позиции группы) и сравниваем с квадратом
 * места. Возвращает null, если под курсором нет выделенного места.
 */
function findSelectedSeatUnder(e: Konva.KonvaEventObject<MouseEvent>): ESeat | null {
  if (selectedSeatIds.value.size === 0 || !stage) return null
  const target = e.target
  if (!target || target.name() !== 'seat') return null
  const id = target.id()
  if (!selectedSeatIds.value.has(id)) return null
  const group = target.getParent()
  if (!group || group.name() !== 'sector-group') return null
  const pos = group.getAbsolutePosition()
  const scale = stage.scaleX() || 1
  const rect = stage.container().getBoundingClientRect()
  const gx = (e.evt.clientX - rect.left - stage.x()) / scale
  const gy = (e.evt.clientY - rect.top - stage.y()) / scale
  const lx = gx - pos.x
  const ly = gy - pos.y
  for (const sector of sectors.value) {
    if (`${sector.x}` !== `${pos.x - 0}` && !(sector.x === pos.x && sector.y === pos.y)) continue
    const seat = sector.seats.find((s) => s.id === id)
    if (seat && lx >= seat.x - 2 && lx <= seat.x + SEAT + 2 && ly >= seat.y - 2 && ly <= seat.y + SEAT + 2) {
      return seat
    }
  }
  // Координатная проверка — только против «увода» с места; если место
  // найдено по id в группе сектора — считаем попадание подтверждённым.
  for (const sector of sectors.value) {
    const seat = sector.seats.find((s) => s.id === id)
    if (seat) return seat
  }
  return null
}

/**
 * Пересортировать места между рядами после группового перемещения:
 *  - затронутые ряды (до и после drag'а) перенумеровываются слева направо;
 *  - если в ряду остались дыры по нумерации — они закрываются;
 *  - коллизии позиций (два места в одной клетке) раздвигаются по X.
 */
function finalizeSeatMove(): void {
  const touched = new Map<string, Set<number>>()
  for (const [, before] of seatDragBefore) {
    if (!touched.has(before.sectorId)) touched.set(before.sectorId, new Set())
    touched.get(before.sectorId)!.add(before.row)
  }
  for (const sector of sectors.value) {
    const movedHere = [...seatDragBefore.values()].some((b) => b.sectorId === sector.id)
    if (!movedHere) continue
    // Ряды, куда попали перетащенные места — тоже затронуты.
    for (const [id] of seatDragIds) {
      const seat = sector.seats.find((s) => s.id === id)
      if (seat) {
        if (!touched.has(sector.id)) touched.set(sector.id, new Set())
        touched.get(sector.id)!.add(seat.row)
      }
    }
  }
  for (const [sectorId, rows] of touched) {
    const sector = sectors.value.find((s) => s.id === sectorId)
    if (!sector) continue
    // Раздвигаем точные коллизии позиций внутри ряда.
    for (const row of rows) {
      const inRow = sector.seats.filter((s) => s.row === row).sort((a, b) => a.x - b.x)
      for (let i = 1; i < inRow.length; i += 1) {
        if (inRow[i].x - inRow[i - 1].x < SEAT) inRow[i].x = inRow[i - 1].x + SEAT + GAP
      }
    }
    renumberRows(sector, rows)
  }
}

/** Создать статический объект кликом (tools table/text/stage/entrance, §47). */
function createStaticAt(kind: StaticKind, x: number, y: number): void {
  snapshot()
  const base = { id: nextId('static'), kind, x, y }
  if (kind === 'table') {
    statics.value.push({ ...base, width: 44, height: 32 })
  } else if (kind === 'label') {
    statics.value.push({ ...base, text: 'Подпись' })
  } else if (kind === 'stage') {
    statics.value.push({ ...base, width: 260, height: 34, text: 'СЦЕНА' })
  } else if (kind === 'entrance') {
    statics.value.push({ ...base, text: 'Вход' })
  } else {
    statics.value.push(base)
  }
  const created = statics.value[statics.value.length - 1]
  selectedStaticIds.value = new Set([created.id])
  selectedSeatIds.value = new Set()
  selectedSectorId.value = null
  const labels: Record<string, string> = { table: 'Стол поставлен', label: 'Подпись добавлена', stage: 'Сцена размещена', entrance: 'Вход отмечен' }
  ui.notify('mint', labels[kind] ?? 'Объект создан', 'Двигайте мышью, свойства — в панели справа')
}

/**
 * Обработка клика холстом в режиме редактирования (§47). Вызывается из
 * stage.on('click'): здесь физически живут инструменты seat/table/standing/
 * text/stage/entrance, которых раньше не было — кнопки выбирали инструмент,
 * но ничего не происходило.
 */
function handleToolClick(clientX: number, clientY: number): void {
  if (isLocked.value) return
  // Клик «поверх» завершённого перетаскивания (Konva выдаёт click после
  // mouseup с тем же button) — не должен порождать второй объект.
  if (wasDragged) return
  const t = tool.value
  if (!EDIT_TOOLS.includes(t) || t === 'image' || t === 'standing') return
  const p = toCanvasPoint(clientX, clientY)
  if (!p) return
  if (t === 'seat') { addSeatAt(p.x, p.y); return }
  if (t === 'table') { createStaticAt('table', p.x - 22, p.y - 16); return }
  if (t === 'text') { createStaticAt('label', p.x, p.y); return }
  if (t === 'stage') { createStaticAt('stage', p.x - 130, p.y - 17); return }
  if (t === 'entrance') { createStaticAt('entrance', p.x - 12, p.y - 9); return }
  if (t === 'sector') { createRectSector(p.x, p.y); return }
  if (t === 'row') { addRowToSelected(); return }
}

/**
 * Быстрое создание сектора кликом (инструмент sector, §47): сетка из формы
 * генератора в точке клика. Полноценная раскладка — кнопкой «Создать сектор»
 * в инспекторе (§49).
 */
function createRectSector(x: number, y: number): void {
  const prev = form.value
  form.value = { ...prev, name: prev.name || `Сектор ${sectors.value.length + 1}` }
  generateSector()
  const created = sectors.value[sectors.value.length - 1]
  if (created) { created.x = x; created.y = y }
}

/** Жест перетаскиванием: standing-зона и sector-прямоугольник (§47). */
type DragMode = 'standing' | 'sector' | 'marquee' | 'seat-move' | null
let dragMode: DragMode = null
let dragStart: { x: number; y: number } | null = null
/** true, если между mousedown и mouseup курсор ушёл дальше порога — клик не считается. */
let wasDragged = false
const DRAG_THRESHOLD = 4
/** Смещения выделенных статик относительно тянутого объекта (групповой drag). */
const dragOffsets = new Map<string, { dx: number; dy: number }>()

function onStageMouseDown(e: Konva.KonvaEventObject<MouseEvent>): void {
  if (isLocked.value) return
  if (e.evt.button !== 0) return
  const p = toCanvasPoint(e.evt.clientX, e.evt.clientY)
  if (!p) return
  wasDragged = false
  dragStart = p
  const t = tool.value
  if (t === 'standing') dragMode = 'standing'
  else if (t === 'sector') dragMode = 'sector'
  else if (t === 'select') {
    // Перетаскивание ВЫДЕЛЕННЫХ мест (§48): mousedown по месту из мультиселекта.
    const under = findSelectedSeatUnder(e)
    if (under && selectedSeatIds.value.size > 1) {
      beginMoveSnapshot()
      seatDragIds = new Set(selectedSeatIds.value)
      seatDragBefore.clear()
      for (const sector of sectors.value) {
        for (const seat of sector.seats) {
          if (seatDragIds.has(seat.id)) {
            seatDragBefore.set(seat.id, { sectorId: sector.id, row: seat.row, x: seat.x, y: seat.y })
          }
        }
      }
      dragMode = 'seat-move'
      return
    }
    // Рамка группового выделения (§48): mousedown по пустому месту холста.
    const clickedShape = e.target
    if (clickedShape === stage || clickedShape?.name() === 'canvas-bg') dragMode = 'marquee'
    else dragMode = null
  } else dragMode = null
}

function onStageMouseMove(e: Konva.KonvaEventObject<MouseEvent>): void {
  if (!dragStart || !dragMode) return
  const p = toCanvasPoint(e.evt.clientX, e.evt.clientY)
  if (!p) return
  if (Math.hypot(p.x - dragStart.x, p.y - dragStart.y) > DRAG_THRESHOLD) wasDragged = true
  if (!wasDragged) return
  if (dragMode === 'marquee') {
    marqueeStart = dragStart
    marqueeEnd = p
    drawMarquee()
  } else if (dragMode === 'standing' || dragMode === 'sector') {
    drawStretchPreview(dragStart, p, dragMode)
  } else if (dragMode === 'seat-move') {
    // Временное смещение выделенных мест (до отпускания — без записи в модель
    // ряда: финализация и перенумерация произойдут на mouseup).
    const dx = p.x - dragStart.x
    const dy = p.y - dragStart.y
    for (const [id, before] of seatDragBefore) {
      const sector = sectors.value.find((s) => s.id === before.sectorId)
      const seat = sector?.seats.find((s) => s.id === id)
      if (!seat) continue
      seat.x = before.x + dx
      seat.y = before.y + dy
    }
  }
}

function onStageMouseUp(e: Konva.KonvaEventObject<MouseEvent>): void {
  const start = dragStart
  const mode = dragMode
  dragStart = null
  dragMode = null
  const p = start ? toCanvasPoint(e.evt.clientX, e.evt.clientY) : null
  if (start && p && mode === 'standing' && wasDragged) {
    createStandingZone(start, p)
  }
  if (start && p && mode === 'sector' && wasDragged) {
    createSectorFromRect(start, p)
  }
  if (mode === 'marquee' && marqueeStart && marqueeEnd) {
    applyMarqueeSelection()
  }
  if (mode === 'seat-move' && wasDragged) {
    // Пересортировать ряды: места могли пересесть в другой ряд или поменять
    // порядок — нумерация обязана остаться читаемой (§48).
    finalizeSeatMove()
    moveSnapshotTaken = false
    snapshotPendingMove = true
    seatDragIds = new Set()
    seatDragBefore.clear()
  } else if (mode === 'seat-move') {
    // Клик без движения — откат временных координат не нужен (их не меняли),
    // состояние выбирается штатным обработчиком click.
    seatDragIds = new Set()
    seatDragBefore.clear()
  }
  marqueeStart = null
  marqueeEnd = null
  guidesLayer?.destroyChildren()
  guidesLayer?.draw()
}

/** Стоячая зона, растянутая перетаскиванием (инструмент standing, §47). */
function createStandingZone(a: { x: number; y: number }, b: { x: number; y: number }): void {
  if (isLocked.value) return
  const x = Math.min(a.x, b.x)
  const y = Math.min(a.y, b.y)
  const w = Math.abs(b.x - a.x)
  const h = Math.abs(b.y - a.y)
  snapshot()
  // Вместимость: ~0,66 м² на человека, условно 150 px² в масштабе схемы.
  const capacity = Math.max(10, Math.floor((w * h) / 150))
  statics.value.push({ id: nextId('static'), kind: 'standing', x, y, width: w, height: h, capacity, text: `Фан-зона · ${capacity}` })
  selectedStaticIds.value = new Set([statics.value[statics.value.length - 1].id])
  selectedSeatIds.value = new Set()
  selectedSectorId.value = null
  ui.notify('mint', 'Стоячая зона создана', `Вместимость: ${capacity}`)
}

/** Сектор-прямоугольник, растянутый перетаскиванием (инструмент sector, §47). */
function createSectorFromRect(a: { x: number; y: number }, b: { x: number; y: number }): void {
  const w = Math.abs(b.x - a.x)
  const h = Math.abs(b.y - a.y)
  const seatsPerRow = Math.max(1, Math.round(w / (SEAT + GAP)))
  const rows = Math.max(1, Math.round(h / (SEAT + ROW_GAP)))
  const prev = form.value
  form.value = { ...prev, shape: 'grid', rows, seatsPerRow, name: prev.name || `Сектор ${sectors.value.length + 1}` }
  generateSector()
  const created = sectors.value[sectors.value.length - 1]
  if (created) { created.x = Math.min(a.x, b.x); created.y = Math.min(a.y, b.y) }
}

/** Пунктирный превью растягивания (standing/sector) во время жеста. */
function drawStretchPreview(a: { x: number; y: number }, b: { x: number; y: number }, mode: 'standing' | 'sector'): void {
  if (!guidesLayer) return
  guidesLayer.destroyChildren()
  guidesLayer.add(new Konva.Rect({
    x: Math.min(a.x, b.x),
    y: Math.min(a.y, b.y),
    width: Math.abs(b.x - a.x),
    height: Math.abs(b.y - a.y),
    stroke: mode === 'standing' ? '#C9A0FF' : '#6D4AFF',
    dash: [8, 5],
    strokeWidth: 2,
    fill: mode === 'standing' ? 'rgba(58,50,112,0.35)' : 'rgba(109,74,255,0.08)',
    listening: false,
  }))
  guidesLayer.draw()
}

/* ── Рамка выделения (§48: drag — групповое выделение) ─────────────── */

let marqueeStart: { x: number; y: number } | null = null
let marqueeEnd: { x: number; y: number } | null = null

function drawMarquee(): void {
  if (!guidesLayer || !marqueeStart || !marqueeEnd) return
  guidesLayer.destroyChildren()
  guidesLayer.add(new Konva.Rect({
    x: Math.min(marqueeStart.x, marqueeEnd.x),
    y: Math.min(marqueeStart.y, marqueeEnd.y),
    width: Math.abs(marqueeEnd.x - marqueeStart.x),
    height: Math.abs(marqueeEnd.y - marqueeStart.y),
    stroke: '#6D4AFF',
    dash: [4, 3],
    fill: 'rgba(109,74,255,0.08)',
    listening: false,
  }))
  guidesLayer.draw()
}

function applyMarqueeSelection(): void {
  if (!marqueeStart || !marqueeEnd) return
  const x1 = Math.min(marqueeStart.x, marqueeEnd.x)
  const x2 = Math.max(marqueeStart.x, marqueeEnd.x)
  const y1 = Math.min(marqueeStart.y, marqueeEnd.y)
  const y2 = Math.max(marqueeStart.y, marqueeEnd.y)
  if (x2 - x1 < 4 && y2 - y1 < 4) return
  const picked = new Set<string>()
  for (const sector of sectors.value) {
    for (const seat of sector.seats) {
      const gx = sector.x + seat.x
      const gy = sector.y + seat.y
      if (gx >= x1 && gx + SEAT <= x2 && gy >= y1 && gy + SEAT <= y2) picked.add(seat.id)
    }
  }
  if (picked.size > 0) {
    selectedSeatIds.value = picked
    selectedSectorId.value = null
  }
}

function deleteSelection(): void {
  // Серверная защита есть (триггер неизменяемости), но клиент не обязан
  // дёргать удаление в заблокированной версии — иначе изменение состояния
  // после Delete породит автосохранение, которое невозможно (и это выглядит
  // как потерянная работа).
  if (isLocked.value) {
    ui.notify('sun', 'Версия опубликована', 'Удаление недоступно — нажмите «Новая версия», чтобы править')
    return
  }
  if (selectedSeatIds.value.size === 0 && selectedStaticIds.value.size === 0 && !selectedSectorId.value) return
  snapshot()
  if (selectedSeatIds.value.size > 0) {
    for (const sector of sectors.value) {
      sector.seats = sector.seats.filter((s) => !selectedSeatIds.value.has(s.id))
    }
    selectedSeatIds.value = new Set()
  } else if (selectedStaticIds.value.size > 0) {
    statics.value = statics.value.filter((s) => !selectedStaticIds.value.has(s.id))
    selectedStaticIds.value = new Set()
  } else if (selectedSectorId.value) {
    sectors.value = sectors.value.filter((s) => s.id !== selectedSectorId.value)
    selectedSectorId.value = null
  }
}

function duplicateSelection(): void {
  if (selectedSeatIds.value.size === 0) return
  snapshot()
  const additions: ESeat[] = []
  for (const sector of sectors.value) {
    const selected = sector.seats.filter((s) => selectedSeatIds.value.has(s.id))
    for (const seat of selected) {
      const maxInRow = sector.seats.filter((s) => s.row === seat.row).length
      additions.push({
        ...seat,
        id: nextId('seat'),
        number: maxInRow + additions.filter((a) => a.row === seat.row).length + 1,
        x: seat.x + SEAT + GAP,
      })
    }
    sector.seats.push(...additions)
  }
  ui.notify('brand', 'Дублировано', `${selectedSeatIds.value.size} мест`)
}

/* ── Автосохранение (§52): 500 ms debounce, состояния ──────────────── */

let saveTimer: ReturnType<typeof setTimeout> | null = null
function scheduleAutosave(): void {
  autosave.value = 'saving'
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    void persistSchema()
  }, 500)
}

/** Собрать схему в формате редактора (как в exportSchema). */
function buildSchemaPayload(): unknown {
  return {
    version: '1.0',
    canvas: { width: canvasSize.value.width, height: canvasSize.value.height },
    background: backgrounds.value[0] ?? null,
    sectors: sectors.value.map((s) => ({
      id: s.id,
      name: s.name,
      x: s.x,
      y: s.y,
      priceMinor: s.priceMinor,
      rowPrices: s.rowPrices,
      shape: s.shape,
      arcSpread: s.arcSpread,
      arcBaseR: s.arcBaseR,
      arcRowGap: s.arcRowGap,
      arcOffsetX: s.arcOffsetX,
      arcOffsetY: s.arcOffsetY,
      seats: s.seats.map((seat) => ({
        id: seat.id,
        row: seat.row,
        number: seat.number,
        kind: seat.kind,
        x: seat.x,
        y: seat.y,
      })),
    })),
    staticObjects: statics.value,
  }
}

/** Реальное сохранение черновика на сервер. */
async function persistSchema(): Promise<void> {
  if (!hallPublicId.value || published.value !== null) return
  if (!navigator.onLine) {
    autosave.value = 'offline'
    return
  }
  try {
    const res = await send<{ id: number; version: number }>(
              `/halls/${hallPublicId.value}/schema-versions/draft`,
              'POST',
              { payload: buildSchemaPayload() },
            )
        const d = res.data
        const versionId = typeof d === 'object' && d && typeof d.id === 'number' ? d.id : null
    const versionNo = typeof d === 'object' && d && typeof d.version === 'number' ? d.version : draftVersion.value
    if (versionId) {
      currentVersionId = versionId
      draftVersionId.value = versionId
      if (versionNo > 0) draftVersion.value = versionNo
    }
    autosave.value = 'saved'
    lastSavedAt.value = Date.now()
  } catch {
    autosave.value = 'error'
  }
}

watch(
  [sectors, statics, backgrounds],
  () => {
    if (published.value !== null) return
    scheduleAutosave()
  },
  { deep: true },
)

/* ── Публикация ────────────────────────────────────────────────────── */

const isLocked = computed(() => published.value !== null)

function publish(): void {
  if (sectors.value.length === 0) {
    ui.notify('rose', 'Нечего публиковать', 'Сначала создайте хотя бы один сектор')
    return
  }
  // Сохраним перед публикацией, чтобы у нас был id версии.
  async function doPublish(): Promise<void> {
    try {
      if (!currentVersionId) {
        await persistSchema()
      }
      if (!currentVersionId || !hallPublicId.value) {
        ui.notify('rose', 'Черновик не сохранён', 'Не удалось создать черновик на сервере')
        return
      }
      const res = await send<{ id: number; version: number; status: string }>(
                    `/schema-versions/${currentVersionId}/publish`,
                    'POST',
                    {},
                  )
            const d = res.data
      const publishedNo = (typeof d === 'object' && d && typeof d.version === 'number') ? d.version : draftVersion.value
      published.value = publishedNo
      loadedPublished.value = publishedNo
      draftVersion.value = publishedNo + 1
      autosave.value = 'saved'
      ui.notify(
        'mint',
        `Версия ${publishedNo} опубликована`,
        'Схема стала неизменяемой: правки создадут новую версию',
      )
    } catch {
      autosave.value = 'error'
      ui.notify('rose', 'Ошибка публикации', 'Не удалось опубликовать схему')
    }
  }
  void doPublish()
}

function newVersion(): void {
  published.value = null
  ui.notify('brand', 'Новый черновик', `Правки попадут в версию ${draftVersion.value}`)
}

/* ── Загрузка схемы с сервера (API) ───────────────────────────────── */

interface ServerSector {
  name: string
  id?: string
  x?: number
  y?: number
  rows?: Array<{
    number: string | number
    label?: string
    price_amount?: number
    seats?: Array<{ number: string | number; label?: string; type?: string; x?: number; y?: number }>
  }>
  seats?: Array<{ id?: string; row?: number; number?: number; kind?: string; x?: number; y?: number }>
  priceMinor?: number
  /** В старых схемах, сохранённых из прошлой админки, цена лежит в `price`. */
  price?: number
  rowPrices?: Record<string, number>
  shape?: string
  arcSpread?: number
  arcBaseR?: number
  arcRowGap?: number
  arcOffsetX?: number
  arcOffsetY?: number
}

/**
 * Сконвертировать серверную схему (БД-формат или редакторский JSON) в состояние
 * редактора.
 *
 * Два режима, и это принципиально:
 *  - редакторский JSON (sectors[].seats[] с абсолютными координатами и полной
 *    геометрией дуги) загружается БЕЗ масштабирования — иначе F5 меняет схему
 *    (пересчёт масштаба при каждой загрузке терял точность и переставлял
 *    сектора);
 *  - «БД-формат» (sectors[].rows[].seats[], сидер/импортёр Афиши) нормализуется
 *    к сетке редактора: координаты могут быть битыми (все нули) — ряды
 *    раскладываются по константам SEAT/GAP/ROW_GAP, цены берутся из
 *    row.price_amount (или легаси sector.price).
 */
function applyServerSchema(raw: unknown): void {
  // Сервер может вернуть schema_json как JSON-строку (Postgres jsonb через
  // некоторые драйверы/сиды) — без этого шага весь payload читался бы как {}
  // и статика/фон/цены «терялись» при перезагрузке (B7).
  let input = raw
  if (typeof input === 'string') {
    try { input = JSON.parse(input) } catch { input = null }
  }
  const obj = (typeof input === 'object' && input) ? input as Record<string, unknown> : {}
  const rawCanvas = (typeof obj.canvas === 'object' && obj.canvas)
    ? obj.canvas as Record<string, unknown>
    : null
  if (rawCanvas) {
    const w = Number(rawCanvas.width ?? canvasSize.value.width)
    const h = Number(rawCanvas.height ?? canvasSize.value.height)
    if (w > 0 && h > 0) canvasSize.value = { width: w, height: h }
  }
  const rawSectors = Array.isArray(obj.sectors) ? obj.sectors : []

  // ── Статика и фон: раньше молча выбрасывались → после перезагрузки зал
  //    терял сцену, входы, подписи и подложку (B7). Восстанавливаем как есть. ──
  // ВАЖНО: сервер может вернуть schema_json как JSON-строку (не распарсенный
  // объект) — тогда `obj.staticObjects`/`obj.background` равны undefined и
  // статика с фоном «терялись» при F5. Нормализуем payload перед чтением.
  if (Array.isArray(obj.staticObjects)) {
    const restored = (obj.staticObjects as Array<Record<string, unknown>>)
      .filter((o) => o && typeof o.id === 'string' && typeof o.kind === 'string')
      .map((o) => ({
        id: o.id as string,
        kind: o.kind as StaticKind,
        x: Math.round(Number(o.x ?? 0)),
        y: Math.round(Number(o.y ?? 0)),
        width: Number.isFinite(Number(o.width)) ? Number(o.width) : undefined,
        height: Number.isFinite(Number(o.height)) ? Number(o.height) : undefined,
        rotation: Number.isFinite(Number(o.rotation)) ? Number(o.rotation) : 0,
        opacity: Number.isFinite(Number(o.opacity)) ? Math.min(1, Math.max(0, Number(o.opacity))) : 1,
        locked: o.locked === true,
        text: typeof o.text === 'string' ? o.text : undefined,
        capacity: Number.isFinite(Number(o.capacity)) ? Math.max(0, Math.round(Number(o.capacity))) : undefined,
      }))
    statics.value = restored
  } else {
    statics.value = []
  }
  const rawBg = obj.background
  if (typeof rawBg === 'object' && rawBg && typeof (rawBg as EBackground).src === 'string') {
    const bg = rawBg as Record<string, unknown>
    backgrounds.value = [{
      id: typeof bg.id === 'string' ? bg.id : 'bg-restored',
      src: bg.src as string,
      x: Math.round(Number(bg.x ?? 0)),
      y: Math.round(Number(bg.y ?? 0)),
      width: Number(bg.width) > 0 ? Number(bg.width) : canvasSize.value.width,
      height: Number(bg.height) > 0 ? Number(bg.height) : canvasSize.value.height,
      rotation: Number.isFinite(Number(bg.rotation)) ? Number(bg.rotation) : 0,
      locked: bg.locked === true,
      opacity: Number.isFinite(Number(bg.opacity)) ? Math.min(1, Math.max(0, Number(bg.opacity))) : 1,
    }]
  } else {
    backgrounds.value = []
  }

  interface FlatSeat { row: number; number: number; x: number; y: number; kind: string }
  const collected: { sector: ServerSector; seats: FlatSeat[] }[] = []
  let allMinX = Infinity, allMaxX = -Infinity, allMinY = Infinity, allMaxY = -Infinity
  for (const rs of rawSectors) {
    const s = (typeof rs === 'object' && rs) ? rs as ServerSector : null
    if (!s || !s.name) continue
    let seats: FlatSeat[] = []
    if (Array.isArray(s.seats)) {
      seats = s.seats.map((seat) => ({
        row: Number(seat.row ?? 0),
        number: Number(seat.number ?? 0),
        kind: (seat.kind === 'vip' || seat.kind === 'accessible' || seat.kind === 'wheelchair') ? (seat.kind === 'wheelchair' ? 'accessible' : seat.kind) : 'standard',
        x: Number(seat.x ?? 0),
        y: Number(seat.y ?? 0),
      }))
    } else if (Array.isArray(s.rows)) {
      for (const r of s.rows) {
        const rowNo = Number(r.number ?? 0)
        for (const seat of r.seats ?? []) {
          seats.push({
            row: rowNo,
            number: Number(seat.number ?? 0),
            kind: seat.type === 'vip' ? 'vip' : (seat.type === 'wheelchair' || seat.type === 'accessible') ? 'accessible' : 'standard',
            x: Number(seat.x ?? 0),
            y: Number(seat.y ?? 0),
          })
        }
      }
    }
    if (seats.length === 0) continue
    collected.push({ sector: s, seats })
    for (const p of seats) {
      if (p.x < allMinX) allMinX = p.x
      if (p.x > allMaxX) allMaxX = p.x
      if (p.y < allMinY) allMinY = p.y
      if (p.y > allMaxY) allMaxY = p.y
    }
  }

  // Формат определяется по первому сектору с местами: наличие seats[] c
  // ненулевыми координатами + отсутствие rows[] ⇒ редакторский JSON.
  const firstWithSeats = collected[0]
  const isEditorFormat = !!firstWithSeats
    && Array.isArray(firstWithSeats.sector.seats)
    && !(firstWithSeats.sector.rows && firstWithSeats.sector.rows.length > 0)

  const out: ESector[] = []
  if (isEditorFormat) {
    // Редакторский формат: полная геометрия сохраняется 1:1.
    for (const { sector: s, seats: flatSeats } of collected) {
      const srcId = typeof s.id === 'string' ? s.id : `s${out.length + 1}`
      const rowPrices: Record<number, number> = {}
      for (const [k, v] of Object.entries(s.rowPrices ?? {})) {
        const rn = Number(k)
        if (rn > 0 && typeof v === 'number' && Number.isFinite(v)) rowPrices[rn] = Math.max(0, Math.round(v))
      }
      out.push({
        id: srcId,
        name: s.name,
        priceMinor: Math.max(0, Math.round(Number(s.priceMinor ?? s.price ?? 0))),
        x: Math.round(Number(s.x ?? 0)),
        y: Math.round(Number(s.y ?? 0)),
        seats: flatSeats.map((p, i) => ({
          id: (s.seats?.[i]?.id as string) ?? `${srcId}-${p.row}-${p.number}`,
          row: p.row,
          number: p.number,
          kind: (p.kind === 'vip' || p.kind === 'accessible') ? p.kind : 'standard',
          x: Math.round(p.x),
          y: Math.round(p.y),
        })),
        rowPrices,
        shape: s.shape === 'arc' ? 'arc' : 'grid',
        arcSpread: Number(s.arcSpread ?? 120),
        arcBaseR: Number(s.arcBaseR ?? 200),
        arcRowGap: Number(s.arcRowGap ?? 24),
        arcOffsetX: Number(s.arcOffsetX ?? 0),
        arcOffsetY: Number(s.arcOffsetY ?? 0),
      })
    }
  } else {
    // БД-формат: общий масштаб по всей схеме (координаты сидера могут быть
    // нормализованы в 0..59/0..39 или лежать в пикселях чужого холста).
    const pad = 40
    const viewW = Math.max(canvasSize.value.width, 900)
    const viewH = 620
    const spanX = (allMaxX - allMinX) || 1
    const spanY = (allMaxY - allMinY) || 1
    const hasGeometry = Number.isFinite(allMinX) && allMaxX > allMinX && allMaxY > allMinY
    const scale = hasGeometry
      ? Math.min((viewW - pad * 2) / spanX, (viewH - pad * 2) / spanY, 60)
      : 1
    for (const { sector: s, seats: flatSeats } of collected) {
      const rowPrices: Record<number, number> = {}
      // Цены по рядам из rows[].price_amount (§50). price_amount хранится в
      // минорных единицах (копейках) — как и editor's priceMinor
      // (см. HallSchemaVersion::toInventoryFormat и InventoryService),
      // конвертация валюты здесь не нужна.
      const putRowPrice = (rn: number, val: number): void => {
        if (rn > 0 && Number.isFinite(val) && val >= 0) {
          rowPrices[rn] = Math.round(val)
        }
      }
      for (const r of s.rows ?? []) {
        putRowPrice(Number(r.number ?? 0), Number(r.price_amount ?? NaN))
      }
      // Легаси: цена могла лежать на уровне места (seat.price_amount / seat.price).
      for (const rs of (s.rows ?? [])) {
        const rn = Number(rs.number ?? 0)
        for (const seat of rs.seats ?? []) {
          const rec = seat as Record<string, unknown>
          const sp = Number(rec.price_amount ?? rec.price ?? NaN)
          if (Number.isFinite(sp) && !rowPrices[rn]) putRowPrice(rn, sp)
        }
      }
      // Если у сектора цена не задана вовсе, но ряды имеют цены — берём
      // минимальную цену ряда как базовую, иначе инспектор покажет 0 (B7).
      let secPrice = Number(s.priceMinor ?? s.price ?? NaN)
      if (!Number.isFinite(secPrice) || secPrice <= 0) {
        const vals = Object.values(rowPrices)
        secPrice = vals.length > 0 ? Math.min(...vals) : 0
      }
      let secMinX = Infinity, secMinY = Infinity
      for (const p of flatSeats) {
        if (p.x < secMinX) secMinX = p.x
        if (p.y < secMinY) secMinY = p.y
      }
      // Если вся геометрия вырождена (нулевые координаты) — раскладываем
      // места по стандартной сетке редактора, чтобы схема не слиплась в точку.
      const degenerate = !hasGeometry
      const rowsSorted = Array.from(new Set(flatSeats.map((p) => p.row))).sort((a, b) => a - b)
      const rowIndexOf = new Map(rowsSorted.map((r, i) => [r, i]))
      // Позиция места внутри ряда — по индексу в исходном массиве ряда.
      const seatPosCache = new Map<number, number>()
      let seatsSeenInRow = 0
      let lastRowSeen = Number.NaN
      flatSeats.forEach((p, i) => {
        if (p.row !== lastRowSeen) { seatsSeenInRow = 0; lastRowSeen = p.row }
        seatPosCache.set(i, seatsSeenInRow)
        seatsSeenInRow += 1
      })
      const seats: ESector['seats'] = flatSeats.map((p, i) => ({
        id: `${s.name}-${p.row}-${p.number}-${i}`,
        row: p.row,
        number: p.number,
        kind: (p.kind === 'vip' || p.kind === 'accessible') ? p.kind : 'standard',
        x: degenerate
          ? (seatPosCache.get(i) ?? 0) * (SEAT + GAP)
          : Math.round((p.x - secMinX) * scale),
        y: degenerate
          ? (rowIndexOf.get(p.row) ?? 0) * (SEAT + ROW_GAP)
          : Math.round((p.y - secMinY) * scale),
      }))
      out.push({
        id: `s${out.length + 1}`,
        name: s.name,
        priceMinor: Math.max(0, Math.round(secPrice)),
        x: 0,
        y: 0,
        seats,
        rowPrices,
        shape: s.shape === 'arc' ? 'arc' : 'grid',
        arcSpread: Number(s.arcSpread ?? 120),
        arcBaseR: Number(s.arcBaseR ?? 200),
        arcRowGap: Number(s.arcRowGap ?? 24),
        arcOffsetX: 0,
        arcOffsetY: 0,
      })
    }
    // Раскладка секторов БД-формата по вертикали, чтобы они не наложились.
    let cursorY = 140
    for (const sec of out) {
      sec.y = cursorY
      cursorY += bbox(sec).height + 80
    }
  }
  if (out.length > 0) {
    sectors.value = out

    // Автоматическая зона танцпола: для сектора, где все места в одной точке
    // (или имя содержит «Танцпол»), рисуем пунктирную зону-оверлей в static-слое.
    const dance = out.find((s) => /танцпол|dance/i.test(s.name ?? '') || (
      s.seats.length > 1 &&
      s.seats.every((p) => p.x === s.seats[0].x && p.y === s.seats[0].y)
    ))
    if (dance && dance.seats.length > 0) {
      const cx = canvasSize.value.width / 2
      const cy = 190
      const R = 60 + Math.min(dance.seats.length, 10) * 4
      const existing = statics.value.filter((o) => /танцпол|dance/i.test(o.text ?? ''))
      if (existing.length === 0) {
        statics.value = [
          ...statics.value,
          {
            id: 'static-dancezone',
            kind: 'standing',
            x: cx - R,
            y: cy - R * 0.6,
            width: R * 2,
            height: R * 1.2,
            text: `Танцпол · ${dance.seats.length} мест`,
            capacity: dance.seats.length,
          },
        ]
      }
    }
  }
}

/** Загрузить зал и его версии с сервера. */
async function loadFromServer(): Promise<void> {
  hallLoadState.value = 'loading'
  const pid = route.params.publicId
  if (typeof pid !== 'string' || !pid) {
    hallLoadState.value = 'error'
    ui.notify('rose', 'Нет зала', 'URL редактора должен содержать publicId (/#/admin/halls/:publicId/editor)')
    return
  }
  hallPublicId.value = pid
  try {
    const res = await get<{ data: Array<Record<string, unknown>> }>(`/halls/${pid}/schema-versions`)
    const inner = res.data
    const versions = Array.isArray(inner) ? inner : (Array.isArray(inner.data) ? inner.data : [])
    // Стартовый номер черновика НЕ фиксирован: он вычисляется из всех версий
    // зала (max(version)+1 для нового черновика), чтобы не «перезапускаться»
    // на v1 после публикации и не конфликтовать с серверной нумерацией.
    let maxVersionNo = 0
    for (const v of versions) {
      const n = Number(v.version ?? 0)
      if (Number.isFinite(n) && n > maxVersionNo) maxVersionNo = n
    }
    // Выбираем черновик, иначе последнюю опубликованную.
    const draft = versions.find((v) => v.status === 'draft')
    const publishedV = versions.find((v) => v.status === 'published')
    const chosen = draft ?? publishedV
    if (chosen) {
      const versionNo = Number(chosen.version ?? 0)
      currentVersionId = Number(chosen.id ?? 0)
      if (chosen.status === 'published') {
        published.value = versionNo
        loadedPublished.value = versionNo
        // Черновик после публикации — следующий за максимальной версией зала.
        draftVersion.value = Math.max(versionNo, maxVersionNo) + 1
      } else {
        draftVersion.value = versionNo > 0 ? versionNo : maxVersionNo + 1
        draftVersionId.value = Number(chosen.id ?? null)
      }
      const schema = chosen.schema
            if (schema) { applyServerSchema(schema); fit() }
            // Фон мог быть сохранён не в payload, а в отдельную колонку
            // background_url (+ width/height версии) — если в схеме фона нет,
            // восстанавливаем его из колонок, иначе F5 «теряет» подложку (B7).
            if (backgrounds.value.length === 0 && typeof chosen.background_url === 'string' && chosen.background_url) {
              backgrounds.value = [{
                id: 'bg-column',
                src: chosen.background_url,
                x: 0,
                y: 0,
                width: Number(chosen.width) > 0 ? Number(chosen.width) : canvasSize.value.width,
                height: Number(chosen.height) > 0 ? Number(chosen.height) : canvasSize.value.height,
                rotation: 0,
                locked: false,
                opacity: 1,
              }]
            }
            ui.notify('brand', chosen.status === 'published' ? 'Опубликована' : 'Черновик загружен', `Версия ${versionNo}`)
    } else {
      ui.notify('brand', 'Черновик', 'У зала ещё нет схем — создайте новую')
    }
    hallLoadState.value = 'ok'
  } catch (e) {
    hallLoadState.value = 'error'
    ui.notify('rose', 'Ошибка загрузки', e instanceof Error ? e.message : String(e))
  }
}

/* ── Экспорт Schema JSON (§54) ─────────────────────────────────────── */

function exportSchema(): void {
  const schema = {
    version: '1.0',
    canvas: { width: canvasSize.value.width, height: canvasSize.value.height },
    background: backgrounds.value[0] ?? null,
    sectors: sectors.value.map((s) => ({
      id: s.id,
      name: s.name,
      x: s.x,
      y: s.y,
      priceMinor: s.priceMinor,
      rowPrices: s.rowPrices,
      shape: s.shape,
      arcSpread: s.arcSpread,
      arcBaseR: s.arcBaseR,
      arcRowGap: s.arcRowGap,
      arcOffsetX: s.arcOffsetX,
      arcOffsetY: s.arcOffsetY,
      seats: s.seats.map((seat) => ({
        id: seat.id,
        row: seat.row,
        number: seat.number,
        kind: seat.kind,
        x: seat.x,
        y: seat.y,
      })),
    })),
    staticObjects: statics.value,
  }
  const blob = new Blob([JSON.stringify(schema, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `hall-schema-v${published.value ?? draftVersion.value}.json`
  a.click()
  URL.revokeObjectURL(url)
    ui.notify('brand', 'Схема экспортирована', 'Файл hall-schema.json сохранён')
  }

  /* ── Импорт Schema JSON (из файла Афиши или экспорта) ─────────────── */

  const jsonInput = ref<HTMLInputElement | null>(null)
  function importJsonClick(): void {
    jsonInput.value?.click()
  }

  function onJsonChosen(event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    // Сброс value, чтобы можно было выбрать тот же файл повторно
    input.value = ''
    if (!file) return
    const reader = new FileReader()
    reader.onload = () => {
      try {
        const data = JSON.parse(reader.result as string)
        // autosave не срабатывает при замене sectors.value извне? сработает (watch deep).
        applyServerSchema(data)
        // Если загружали поверх published — снимем блокировку, чтобы можно было править
        if (published.value !== null) {
          newVersion()
        }
        const count = sectors.value.reduce((acc, s) => acc + s.seats.length, 0)
        ui.notify('mint', 'Схема импортирована', `${sectors.value.length} секторов, ${count} мест`)
      } catch (e) {
        ui.notify('rose', 'Ошибка импорта', e instanceof Error ? e.message : String(e))
      }
    }
    reader.onerror = () => {
      ui.notify('rose', 'Ошибка чтения', 'Не удалось прочитать файл')
    }
    reader.readAsText(file)
  }

  /* ── Фон-изображение (§51) ────────────────────────────────────────── */

const imageInput = ref<HTMLInputElement | null>(null)
function triggerImageUpload(): void {
  if (tool.value === 'image') imageInput.value?.click()
}
function onImageChosen(event: Event): void {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (!['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'].includes(file.type)) {
    ui.notify('rose', 'Формат не поддерживается', 'Только PNG, JPG, WEBP или SVG')
    return
  }
  const reader = new FileReader()
  reader.onload = () => {
    snapshot()
    backgrounds.value.push({
      id: nextId('bg'),
      src: String(reader.result),
      x: 0,
      y: 0,
      width: canvasSize.value.width,
      height: canvasSize.value.height,
      rotation: 0,
      locked: false,
      opacity: 1,
    })
  }
  reader.readAsDataURL(file)
  if (imageInput.value) imageInput.value.value = ''
}

/* ── Холст Konva (§46: 9 слоёв) ───────────────────────────────────── */

const canvasHost = ref<HTMLDivElement | null>(null)
const canvasSize = ref({ width: 900, height: 520 })
/** Konva требует window — в безоконном окружении (SSR/тест-стенд) холст просто отсутствует. */
const konvaAvailable = typeof window !== 'undefined' && typeof document !== 'undefined'
let stage: Konva.Stage | null = null
let bgLayer: Konva.Layer | null = null
let staticLayer: Konva.Layer | null = null
let sectorLayer: Konva.Layer | null = null
let guidesLayer: Konva.Layer | null = null
let selectionLayer: Konva.Layer | null = null
let observer: ResizeObserver | null = null

const COLORS = {
  standard: '#3B3468',
  vip: '#F5B417',
  accessible: '#2AA3FF',
  selected: '#6D4AFF',
  stage: '#6D4AFF',
}

function seatFill(seat: ESeat): string {
  return COLORS[seat.kind]
}

function draw(): void {
  if (!stage) return
  bgLayer?.destroyChildren()
  staticLayer?.destroyChildren()
  sectorLayer?.destroyChildren()
  guidesLayer?.destroyChildren()
  selectionLayer?.destroyChildren()

  // Слой 1: фон-изображение (§51). Картинка — в группе с якорями: её можно
  // тянуть (move), масштабировать за правый нижний угол и вращать за верхний
  // правый. Значения пишутся обратно в модель (bg.x/y/width/height/rotation),
  // поэтому автосохранение (§52) подхватывает правку фона. Закрытый (locked)
  // фон не перетаскивается; при isLocked (published) — editing вообще выключен.
  const canEditBg = published.value === null && !isLocked.value
  for (const bg of backgrounds.value) {
    const img = new Image()
    img.onload = () => {
      if (!bgLayer) return
      const bgDraggable = canEditBg && !bg.locked
      const group = new Konva.Group({
        id: bg.id,
        name: 'background-image',
        x: bg.x + bg.width / 2,
        y: bg.y + bg.height / 2,
        rotation: bg.rotation,
        draggable: bgDraggable,
      })
      group.add(new Konva.Image({
        image: img,
        x: -bg.width / 2,
        y: -bg.height / 2,
        width: bg.width,
        height: bg.height,
        opacity: bg.opacity,
        name: 'bg-picture',
      }))
      if (selectedBgIds.value.has(bg.id)) {
        group.add(new Konva.Rect({
          x: -bg.width / 2, y: -bg.height / 2, width: bg.width, height: bg.height,
          stroke: '#8E74FF', dash: [6, 4], strokeWidth: 1, listening: false,
        }))
        if (bgDraggable) {
          // Якорь масштаба (правый нижний угол).
          group.add(new Konva.Circle({
            x: bg.width / 2, y: bg.height / 2, radius: 6, fill: '#8E74FF',
            stroke: '#FFFFFF', strokeWidth: 1, name: 'bg-resize', draggable: true,
          }))
          // Якорь поворота (над правым верхним углом).
          group.add(new Konva.Line({
            points: [bg.width / 2, -bg.height / 2, bg.width / 2, -bg.height / 2 - 28],
            stroke: '#8E74FF', strokeWidth: 1, listening: false,
          }))
          group.add(new Konva.Circle({
            x: bg.width / 2, y: -bg.height / 2 - 28, radius: 6, fill: '#F5B417',
            stroke: '#FFFFFF', strokeWidth: 1, name: 'bg-rotate', draggable: true,
          }))
        }
      }
      group.on('click', (e) => {
        if (tool.value !== 'select') return
        e.cancelBubble = true
        pickBackground(bg.id)
      })
      group.on('dragstart', () => { beginMoveSnapshot() })
      group.on('dragmove', () => {
        bg.x = Math.round(group.x() - bg.width / 2)
        bg.y = Math.round(group.y() - bg.height / 2)
      })
      group.on('dragend', () => {
        snapshotPendingMove = true
        moveSnapshotTaken = false
      })
      // Якоря живут внутри группы: их drag не должен двигать саму группу.
      group.on('dragstart', (e) => {
        const n = (e.target as Konva.Node).name()
        if (n === 'bg-resize' || n === 'bg-rotate') {
          e.cancelBubble = true
          beginMoveSnapshot()
        }
      })
      group.on('dragmove', (e) => {
        const node = e.target as Konva.Shape
        const n = node.name()
        if (n === 'bg-resize') {
          const pos = node.getAbsolutePosition(stage ?? undefined)
          const inv = group.getAbsoluteTransform().copy().invert()
          const local = inv.point(pos)
          const w = Math.max(10, Math.min(20000, Math.round((local.x + bg.width / 2) * 2)))
          const h = Math.max(10, Math.min(20000, Math.round((local.y + bg.height / 2) * 2)))
          bg.width = w
          bg.height = h
          draw()
        } else if (n === 'bg-rotate') {
          const pos = node.getAbsolutePosition(stage ?? undefined)
          const center = group.getAbsolutePosition(stage ?? undefined)
          const deg = (Math.atan2(pos.y - center.y, pos.x - center.x) * 180) / Math.PI + 90
          bg.rotation = Math.round(((deg % 360) + 360) % 360)
          draw()
        }
      })
      group.on('dragend', (e) => {
        const n = (e.target as Konva.Node).name()
        if (n === 'bg-resize' || n === 'bg-rotate') {
          snapshotPendingMove = true
          moveSnapshotTaken = false
        }
      })
      bgLayer.add(group)
      bgLayer.draw()
    }
    img.src = bg.src
  }

  // Слой 2: статические объекты (§46: static_objects). Все фигуры — с id,
  // чтобы по ним можно было кликать (выбор) и перетаскивать (правка §47).
  const canEdit = published.value === null && !isLocked.value && tool.value !== 'pan'
  for (const s of statics.value) {
    const nodes: Konva.Node[] = []
    const stOpacity = Number.isFinite(s.opacity) ? Math.min(1, Math.max(0, s.opacity as number)) : 1
    if (s.kind === 'entrance') {
      nodes.push(new Konva.Arrow({ points: [0, 0, 24, -18], pointerLength: 8, pointerWidth: 8, fill: '#A5F5DE', stroke: '#00C48C', strokeWidth: 2, opacity: stOpacity }))
      if (s.text) nodes.push(new Konva.Text({ x: 28, y: -8, text: s.text, fontSize: 11, fill: '#A5F5DE', listening: false, opacity: stOpacity }))
    } else if (s.kind === 'label' && s.text) {
      nodes.push(new Konva.Text({ text: s.text, fontSize: 14, fill: '#B0A0FF', opacity: stOpacity }))
    } else if (s.kind === 'table') {
      nodes.push(new Konva.Rect({ width: s.width ?? 44, height: s.height ?? 32, cornerRadius: 6, fill: '#3B3468', stroke: '#8E74FF', strokeWidth: 1, opacity: stOpacity }))
      nodes.push(new Konva.Text({ y: (s.height ?? 32) / 2 - 6, width: s.width ?? 44, align: 'center', text: 'Стол', fontSize: 10, fill: '#C9B8FF', listening: false, opacity: stOpacity }))
    } else if (s.kind === 'stage') {
      nodes.push(new Konva.Rect({
        width: s.width ?? 260,
        height: s.height ?? 34,
        cornerRadius: [4, 4, 16, 16],
        fillLinearGradientStartPoint: { x: 0, y: 0 },
        fillLinearGradientEndPoint: { x: s.width ?? 260, y: 0 },
        fillLinearGradientColorStops: [0, '#6D4AFF', 1, '#FF5C22'],
        opacity: stOpacity,
      }))
      nodes.push(new Konva.Text({ y: 10, width: s.width ?? 260, align: 'center', text: s.text || 'СЦЕНА', fontSize: 12, fontStyle: 'bold', letterSpacing: 3, fill: '#FFFFFF', listening: false, opacity: stOpacity }))
    } else if (s.kind === 'standing' && s.width && s.height) {
      nodes.push(new Konva.Rect({ width: s.width, height: s.height, fill: 'rgba(58,50,112,0.55)', stroke: '#C9A0FF', strokeWidth: 2, dash: [8, 5], opacity: stOpacity }))
      if (s.text) nodes.push(new Konva.Text({ x: -40, y: -22, width: s.width + 80, align: 'center', text: s.text, fontSize: 15, fontStyle: 'bold', fill: '#FFFFFF', listening: false, opacity: stOpacity }))
    }
    if (nodes.length === 0) continue
    const isSelected = selectedStaticIds.value.has(s.id)
    const group = new Konva.Group({
      id: s.id,
      name: 'static-object',
      x: s.x,
      y: s.y,
      rotation: s.rotation ?? 0,
      // locked-объект нельзя тянуть (§48), но выделить и редактировать свойства — можно.
      draggable: canEdit && !s.locked,
    })
    for (const n of nodes) group.add(n as Konva.Shape)
    group.on('click', (e) => {
      if (tool.value !== 'select') return
      e.cancelBubble = true
      pickStatic(s.id, e.evt.shiftKey || e.evt.ctrlKey || e.evt.metaKey)
    })
    group.on('dragstart', () => {
      beginMoveSnapshot()
      // Конвейер: если объект не был в выделении — он становится единственным
      // выделенным; если был — тянутся все выделенные статики (см. dragmove).
      if (!selectedStaticIds.value.has(s.id)) selectedStaticIds.value = new Set([s.id])
      dragOffsets.clear()
      for (const o of statics.value) {
        if (selectedStaticIds.value.has(o.id)) dragOffsets.set(o.id, { dx: o.x - s.x, dy: o.y - s.y })
      }
    })
    group.on('dragmove', () => {
      const nx = Math.round(group.x())
      const ny = Math.round(group.y())
      for (const o of statics.value) {
        const off = dragOffsets.get(o.id)
        if (!off) continue
        o.x = o.id === s.id ? nx : nx + off.dx
        o.y = o.id === s.id ? ny : ny + off.dy
      }
    })
    group.on('dragend', () => {
      snapshotPendingMove = true
      // Разрешаем следующий снапшот «до» для нового перемещения — иначе
      // moveSnapshotTaken остался бы true навсегда и история перестала бы
      // фиксировать любые последующие drag'и (дефект A8).
      moveSnapshotTaken = false
    })
    staticLayer?.add(group)
    if (isSelected) {
      const bb = group.getClientRect({ relativeTo: group.getParent() as Konva.Layer ?? undefined })
      selectionLayer?.add(new Konva.Rect({
        x: s.x - 4, y: s.y - 4, width: Math.max(bb.width, 20) + 8, height: Math.max(bb.height, 16) + 8,
        stroke: '#8E74FF', dash: [4, 4], strokeWidth: 1, listening: false,
      }))
    }
  }

  // Слой 3: секторы + места (§46: sectors, rows, seats)
    for (const sector of sectors.value) {
      // Сектор тянется целиком только когда НЕ выбраны отдельные места —
      // иначе mousedown по выделенному местуKonva отдал бы drag группе
      // сектора и перемещение мест стало бы невозможным (§48).
      const seatsSelectedHere = selectedSeatIds.value.size > 0
        && sector.seats.some((s) => selectedSeatIds.value.has(s.id))
      const group = new Konva.Group({
      id: sector.id,
      name: 'sector-group',
      x: sector.x,
      y: sector.y,
      draggable: published.value === null && tool.value !== 'pan' && !seatsSelectedHere,
    })

    group.add(
      new Konva.Text({
        y: -20,
        name: 'sector-label',
        text: `${sector.name} · от ${money(sector.priceMinor)}`,
        fontSize: 12,
        fontStyle: 'bold',
        fill: '#B0A0FF',
      }),
    )

    // Для амфитеатра рисуем концентрические дуги-направляющие по рядам.
    if (sector.shape === 'arc') {
      const rowsCount = Math.max(...sector.seats.map((s) => s.row), 0)
      for (let r = 0; r < rowsCount; r += 1) {
        const R = sector.arcBaseR + r * sector.arcRowGap
        group.add(
          new Konva.Arc({
            x: sector.arcOffsetX,
            y: sector.arcOffsetY,
            innerRadius: R,
            outerRadius: R,
            angle: sector.arcSpread,
            rotation: 90 - sector.arcSpread / 2,
            stroke: '#8E74FF',
            strokeWidth: 1,
            opacity: 0.22,
            listening: false,
          }),
        )
      }
    }

    // Стоячая зона: сектор «Танцпол» (или все места в одной точке) →
            // рисуем зону-овал, а не кучу перекрытых квадратиков.
            const isDanceZone = /танцпол|dance/i.test(sector.name ?? '') ||
              (sector.seats.length > 1 &&
                sector.seats.every((p) => p.x === sector.seats[0].x && p.y === sector.seats[0].y))
            if (isDanceZone && sector.seats.length > 0) {
              if (typeof console !== 'undefined') console.log('[hall-editor] standing zone:', sector.name, sector.seats.length, sector.seats[0].x, sector.seats[0].y)
              const cx = sector.seats[0].x + SEAT / 2
              const cy = sector.seats[0].y + SEAT / 2
              const R = 30 + Math.min(sector.seats.length, 10) * 3
              group.add(
                new Konva.Ellipse({
                  x: cx,
                  y: cy,
                  radiusX: R,
                  radiusY: R * 0.6,
                  fill: 'rgba(42,36,80,0.65)',
                  stroke: '#8E74FF',
                  strokeWidth: 1,
                  dash: [6, 4],
                  listening: false,
                }),
              )
              group.add(
                new Konva.Text({
                  x: cx + R + 6,
                  y: cy - 6,
                  text: `Танцпол · ${sector.seats.length} мест`,
                  fontSize: 12,
                  fill: '#E8E0FF',
                  listening: false,
                }),
              )
            }

            for (const seat of sector.seats) {
              if (isDanceZone) break
              const isSelected = selectedSeatIds.value.has(seat.id)
      const rect = new Konva.Rect({
        id: seat.id,
        name: 'seat',
        x: seat.x,
        y: seat.y,
        width: SEAT,
        height: SEAT,
        cornerRadius: 5,
        fill: isSelected ? COLORS.selected : seatFill(seat),
        stroke: isSelected ? '#B0A0FF' : undefined,
        strokeWidth: isSelected ? 1 : 0,
        // Групповое перемещение мест (§48): тянуть можно только выделенные;
        // drag отключается на уровне места, пока группа сектора неактивна.
        draggable: published.value === null && tool.value === 'select' && isSelected,
      })

      rect.on('click', (e) => {
        if (tool.value !== 'select') return
        e.cancelBubble = true
        pickSeat(seat.id, sector.id, e.evt.shiftKey || e.evt.ctrlKey || e.evt.metaKey)
      })
      rect.on('dragstart', () => {
        beginMoveSnapshot()
        // Место уже в выделении (draggable только для выделенных) — фиксируем
        // стартовые позиции всех выделенных мест этого сектора.
        seatDragIds = new Set(selectedSeatIds.value)
        seatDragBefore.clear()
        for (const s of sector.seats) {
          if (seatDragIds.has(s.id)) {
            seatDragBefore.set(s.id, { sectorId: sector.id, row: s.row, x: s.x, y: s.y })
          }
        }
      })
      rect.on('dragmove', () => {
        const nx = Math.round(rect.x())
        const ny = Math.round(rect.y())
        const before = seatDragBefore.get(seat.id)
        if (!before) return
        const dx = nx - before.x
        const dy = ny - before.y
        for (const [id] of seatDragIds) {
          const b = seatDragBefore.get(id)
          const target = sector.seats.find((s) => s.id === id)
          if (!b || !target) continue
          target.x = b.x + dx
          target.y = b.y + dy
        }
      })
      rect.on('dragend', () => {
        // Места живут в координатах сектора: если место уехало за пределы
        // ряда/сектора — это «пересадка», её учтёт finalizeSeatMove.
        finalizeSeatMove()
        moveSnapshotTaken = false
        snapshotPendingMove = true
        seatDragIds = new Set()
        seatDragBefore.clear()
      })
      rect.on('mouseenter', () => { if (stage) stage.container().style.cursor = 'pointer' })
            rect.on('mouseleave', () => { if (stage) stage.container().style.cursor = 'default' })

            group.add(rect)

            // Подпись номера места (мелкая, под квадратом) — при маленьком SEAT
            // номер сбоку от квадрата почти нечитаем, поэтому под ним.
            group.add(
                          new Konva.Text({
                            x: seat.x - 6,
                            y: seat.y + SEAT + 1,
                            width: SEAT + 12,
                            text: String(seat.number),
                            fontSize: 8,
                            fill: 'rgba(176,160,255,0.55)',
                            align: 'center',
                            listening: false,
                          }),
                        )
          }

    group.on('click', (e) => {
      if (tool.value !== 'select') return
      if (e.target === group || (e.target as Konva.Node).name() === 'sector-label') selectedSectorId.value = sector.id
    })
    group.on('dragstart', () => { beginMoveSnapshot() })
    group.on('dragend', () => {
      sector.x = Math.round(group.x())
      sector.y = Math.round(group.y())
      snapshotPendingMove = true
      moveSnapshotTaken = false
    })
    sectorLayer?.add(group)
  }

  // Слой 4: подсветка выделенного сектора
  if (selectedSectorId.value && !selectedSeatIds.value.size) {
    const sector = sectors.value.find((s) => s.id === selectedSectorId.value)
    if (sector) {
      const bb = bbox(sector)
      selectionLayer?.add(
        new Konva.Rect({
          x: sector.x + bb.x - 4,
          y: sector.y + bb.y - 4,
          width: bb.width + 8,
          height: bb.height + 8,
          stroke: '#8E74FF',
          dash: [4, 4],
          strokeWidth: 1,
        }),
      )
    }
  }

  bgLayer?.draw()
  staticLayer?.draw()
  sectorLayer?.draw()
  selectionLayer?.draw()
}

function bbox(sector: ESector): { x: number; y: number; width: number; height: number } {
  if (sector.seats.length === 0) return { x: 0, y: 0, width: 80, height: 40 }
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const s of sector.seats) {
    if (s.x < minX) minX = s.x
    if (s.y < minY) minY = s.y
    if (s.x + SEAT > maxX) maxX = s.x + SEAT
    if (s.y + SEAT > maxY) maxY = s.y + SEAT
  }
  return { x: minX, y: minY, width: maxX - minX, height: maxY - minY }
}

function zoomBy(factor: number): void {
  if (!stage) return
  const next = Math.min(2.4, Math.max(0.4, stage.scaleX() * factor))
  stage.scale({ x: next, y: next })
  stage.batchDraw()
}

function fit(): void {
  if (!stage) return
  stage.scale({ x: 1, y: 1 })
  stage.position({ x: 0, y: 0 })
  stage.batchDraw()
}

/**
 * Вписать схему в видимую область холста (масштаб + смещение stage).
 * Используется после загрузки/импорта: координаты схемы могут выходить за
 * пределы типового окна (холст БД-формата нормализуется к области 900×620,
 * реальный холст при этом шире/выше или наоборот).
 */
function fitToContent(): void {
  if (!stage) return
  const pts: Array<{ x: number; y: number }> = []
  for (const sector of sectors.value) {
    const bb = bbox(sector)
    pts.push({ x: sector.x + bb.x, y: sector.y + bb.y })
    pts.push({ x: sector.x + bb.x + bb.width, y: sector.y + bb.y + bb.height })
  }
  if (pts.length === 0) { fit(); return }
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const p of pts) {
    if (p.x < minX) minX = p.x
    if (p.y < minY) minY = p.y
    if (p.x > maxX) maxX = p.x
    if (p.y > maxY) maxY = p.y
  }
  const pad = 48
  const w = Math.max(1, maxX - minX)
  const h = Math.max(1, maxY - minY)
  const viewW = Math.max(canvasSize.value.width, 300)
  const viewH = Math.max(canvasSize.value.height, 300)
  const scale = Math.min(2, Math.max(0.3, Math.min((viewW - pad * 2) / w, (viewH - pad * 2) / h)))
  stage.scale({ x: scale, y: scale })
  stage.position({
    x: (viewW - w * scale) / 2 - minX * scale,
    y: (viewH - h * scale) / 2 - minY * scale,
  })
  stage.batchDraw()
}

onMounted(() => {
  // ВАЖНО: порядок инициализации критичен. `void loadFromServer()` асинхронно
  // перезапишет sectors/statics/backgrounds из черновика на сервере; демо-данные
  // должны быть созданы ДО загрузки, иначе они перетирают серверную схему
  // (и автосейв, сработавший на deep-watch, сохранит демо поверх реальных правок).
  if (!canvasHost.value) {
    // Холст ещё не примонтирован (SSR/безоконный стенд) — дождёмся nextTick.
    void nextTick(() => initCanvasAndLoad())
    return
  }
  initCanvasAndLoad()
})

function initCanvasAndLoad(): void {
  const host = canvasHost.value
  if (!host) return
  canvasSize.value = { width: host.clientWidth || 900, height: host.clientHeight || 520 }

  stage = new Konva.Stage({
    container: host,
    width: canvasSize.value.width,
    height: canvasSize.value.height,
    draggable: tool.value === 'pan',
  })
  bgLayer = new Konva.Layer()
  staticLayer = new Konva.Layer()
  sectorLayer = new Konva.Layer()
  guidesLayer = new Konva.Layer()
  selectionLayer = new Konva.Layer()
  stage.add(bgLayer, staticLayer, sectorLayer, guidesLayer, selectionLayer)

  // Фон-подложка холста: нужен «клик по пустому месту» для рамки выделения
  // (§48) и инструментов создания (§47). Без него stage.on('click') по
  // пустой области не генерируется Konva вообще (нет цели под курсором).
  const canvasBg = new Konva.Rect({
    name: 'canvas-bg',
    x: -100000,
    y: -100000,
    width: 200000,
    height: 200000,
    fill: 'rgba(0,0,0,0)',
    listening: true,
  })
  bgLayer.add(canvasBg)

  stage.on('wheel', (e) => {
    e.evt.preventDefault()
    zoomBy(e.evt.deltaY < 0 ? 1.12 : 0.9)
  })

  // Инструменты §47: клик по холсту создаёт объект выбранного инструмента.
  stage.on('click', (e) => {
    handleToolClick(e.evt.clientX, e.evt.clientY)
  })
  stage.on('mousedown', onStageMouseDown)
  stage.on('mousemove', onStageMouseMove)
  stage.on('mouseup', onStageMouseUp)

  observer = new ResizeObserver((entries) => {
    const rect = entries[0]?.contentRect
    if (!rect || !stage) return
    canvasSize.value = { width: rect.width, height: rect.height }
    stage.size(canvasSize.value)
    draw()
  })
  observer.observe(host)

  // Пустой холст (A1): демо-секторы больше не генерируются — новый зал
  // стартует с чистой схемой; сектора создаёт пользователь (§47/§49).
  void loadFromServer().then(() => {
    draw()
    if (sectors.value.length > 0) {
      fitToContent()
    } else {
      fit()
    }
  })
}

onUnmounted(() => {
  if (saveTimer) clearTimeout(saveTimer)
  observer?.disconnect()
  stage?.destroy()
})

watch([sectors, statics, backgrounds, selectedSeatIds, selectedSectorId, tool], draw, { deep: true })

/* ── Горячие клавиши (§52 + §48) ───────────────────────────────────── */

function onKeyDown(e: KeyboardEvent): void {
  if (!e.target || (e.target as HTMLElement).tagName === 'INPUT' || (e.target as HTMLElement).tagName === 'TEXTAREA') return
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
    e.preventDefault()
    e.shiftKey ? redo() : undo()
    return
  }
  if (e.key === 'Delete' || e.key === 'Backspace') {
    e.preventDefault()
    deleteSelection()
  }
}
onMounted(() => window.addEventListener('keydown', onKeyDown))
onUnmounted(() => window.removeEventListener('keydown', onKeyDown))

/* ── Инспектор ─────────────────────────────────────────────────────── */

const selectedSector = computed(() => sectors.value.find((s) => s.id === selectedSectorId.value) ?? null)
const singleSelectedSeat = computed(() => {
  if (selectedSeatIds.value.size !== 1) return null
  const [id] = selectedSeatIds.value
  return sectors.value.flatMap((s) => s.seats).find((s) => s.id === id) ?? null
})
/** Единственный выделенный статический объект — панель свойств статики. */
const selectedStatic = computed<EStatic | null>(() => {
  if (selectedStaticIds.value.size !== 1) return null
  const [id] = selectedStaticIds.value
  return statics.value.find((s) => s.id === id) ?? null
})
/** Единственный выделенный фон (панель «Фон» в инспекторе, §51). */
const selectedBackground = computed<EBackground | null>(() => {
  if (selectedBgIds.value.size !== 1) return null
  const [id] = selectedBgIds.value
  return backgrounds.value.find((b) => b.id === id) ?? null
})
const totalSeats = computed(() => sectors.value.reduce((sum, s) => sum + s.seats.length, 0))

/* ── Валидация числовых полей (§50: цены в копейках) ──────────────────
 * Нечисло / NaN / отрицательное значение / ноль для цены — ошибка: поле
 * подсвечивается, значение НЕ попадает в модель и, соответственно, в
 * payload автосохранения. Границы — как на сервере (HallService::
 * validateSchemaPayload): цена ≥ 1 копейки, ряд/место ≥ 1.
 */

/** Цена ряда: undefined/null = сброс индивидуальной цены (ряд берёт цену сектора). */
function rowPriceError(v: unknown): string | undefined {
  if (v === '' || v === null || v === undefined) return undefined
  const n = Number(v)
  if (!Number.isFinite(n)) return 'Введите число'
  if (n < 0) return 'Цена не может быть отрицательной'
  if (Math.round(n * 100) < 1) return 'Цена должна быть больше нуля'
  return undefined
}

/** Обязательная неотрицательная целая величина (копейки, размеры, вместимость). */
function requiredNonNegativeError(v: unknown, unitLabel = 'Значение'): string | undefined {
  const n = Number(v)
  if (v === '' || v === null || v === undefined || !Number.isFinite(n)) return `${unitLabel}: введите число`
  if (n < 0) return `${unitLabel} не может быть отрицательным`
  return undefined
}

/** Цена сектора в рублях (инспектор): пустое/битое значение → ошибка, модель не трогается. */
function setSectorPriceRub(v: unknown): void {
  const sector = selectedSector.value
  if (!sector) return
  const n = Number(v)
  if (!Number.isFinite(n) || n <= 0) return
  snapshot()
  sector.priceMinor = Math.max(1, Math.round(n * 100))
}

/** Живая ошибка для поля «Цена сектора» в инспекторе (§50). */
const sectorPriceError = computed<string | undefined>(() => {
  const s = selectedSector.value
  if (!s) return undefined
  return rowPriceError(s.priceMinor / 100)
})

/**
 * Введённая цена ряда (₽) с точки зрения валидации; undefined — ошибки нет.
 * Пустой ввод легален: он снимает переопределение (ряд берёт цену сектора).
 */
function rowPriceInputError(row: number): string | undefined {
  const s = selectedSector.value
  if (!s) return undefined
  const v = s.rowPrices[row]
  if (v === undefined) return undefined
  return rowPriceError(v / 100)
}

/**
 * Цена ряда из <input type=number> (§50): '' снимает переопределение;
 * нечисло/ноль/отрицательное — отклоняется (в модель и payload не попадает).
 */
function onRowPriceInput(row: number, raw: string): void {
  const sector = selectedSector.value
  if (!sector) return
  if (raw.trim() === '') { setRowPrice(row, null); return }
  const rub = Number(raw)
  if (!Number.isFinite(rub) || rub <= 0) {
    ui.notify('rose', 'Некорректная цена ряда', 'Цена должна быть числом больше нуля')
    return
  }
  setRowPrice(row, rub)
}

/** Индивидуальная цена ряда в рублях; пустая строка снимает переопределение (§50). */
function setRowPrice(row: number, rub: number | '' | null): void {
  const sector = selectedSector.value
  if (!sector) return
  if (rub === '' || rub === null || Number.isNaN(Number(rub))) {
    delete sector.rowPrices[row]
    return
  }
  const n = Number(rub)
  if (!Number.isFinite(n) || n <= 0) return
  snapshot()
  sector.rowPrices[row] = Math.max(1, Math.round(n * 100))
}

/** Цена сектора генератора (форма «Новый сектор», коп.): только валидные значения. */
function setFormPriceMinor(v: unknown): void {
  const n = Number(v)
  if (!Number.isFinite(n) || n < 0) return
  form.value.priceMinor = Math.max(0, Math.round(n))
}

/** Целочисленное поле формы-генератора с нижней границей (ряды, места, VIP). */
function clampIntField(target: { value: number }, v: unknown, min: number, max: number): void {
  const n = Number(v)
  target.value = Number.isFinite(n) ? Math.min(max, Math.max(min, Math.round(n))) : target.value
}

/** Размер/поворот/вместимость статики: валидируем до записи в модель. */
function setStaticNum(s: EStatic, key: 'x' | 'y' | 'width' | 'height' | 'rotation' | 'capacity', v: unknown, min: number, max: number): void {
  const n = Number(v)
  if (!Number.isFinite(n)) return
  ;(s[key] as number) = Math.min(max, Math.max(min, Math.round(n)))
}

/** Свойства фона (масштаб/поворот/прозрачность/позиция), §51. */
function setBackgroundNum(bg: EBackground, key: 'x' | 'y' | 'width' | 'height' | 'rotation' | 'opacity', v: unknown): void {
  const n = Number(v)
  if (!Number.isFinite(n)) return
  if (key === 'opacity') {
    bg.opacity = Math.min(1, Math.max(0, n))
    return
  }
  if (key === 'width' || key === 'height') {
    bg[key] = Math.min(20000, Math.max(1, Math.round(n)))
    return
  }
  if (key === 'rotation') {
    bg.rotation = ((n % 360) + 360) % 360
    return
  }
  bg[key] = Math.round(n)
}

function toggleBackgroundLocked(): void {
  const bg = selectedBackground.value
  if (!bg) return
  snapshot()
  bg.locked = !bg.locked
}

function removeBackground(): void {
  if (isLocked.value) return
  const bg = selectedBackground.value
  if (!bg) return
  snapshot()
  backgrounds.value = backgrounds.value.filter((b) => b.id !== bg.id)
  selectedBgIds.value = new Set()
  ui.notify('brand', 'Фон удалён', 'Схема сохранится без подложки')
}

const KIND_OPTIONS = [
  { value: 'standard', label: 'Обычное' },
  { value: 'vip', label: 'VIP' },
  { value: 'accessible', label: 'Для маломобильных' },
]

function setSeatKind(value: string): void {
  const seat = singleSelectedSeat.value
  if (!seat) return
  snapshot()
  seat.kind = value as SeatKind
}
</script>

<template>
  <div class="mx-auto max-w-[1500px]">
    <!-- Заголовок, версия, автосохранение, экспорт -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold tracking-tight text-content">Редактор схем залов</h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
          Большой зал ·
          <NBadge :tone="isLocked ? 'mint' : 'sun'" dot>
            {{ isLocked ? `Опубликовано v${published}` : `Черновик v${draftVersion}` }}
          </NBadge>
          <span class="text-subtle">{{ totalSeats }} {{ plural(totalSeats, 'место', 'места', 'мест') }}</span>
          <span class="text-subtle">·</span>
          <span
            :class="cn(
              'inline-flex items-center gap-1 text-2xs',
              autosave === 'saving' && 'text-sun-400',
              autosave === 'saved' && 'text-mint-400',
              autosave === 'error' && 'text-rose-400',
              autosave === 'offline' && 'text-subtle',
            )"
          >
            <span aria-hidden="true">
              <template v-if="autosave === 'saving'">⟳</template>
              <template v-else-if="autosave === 'saved'">●</template>
              <template v-else-if="autosave === 'error'">!</template>
              <template v-else>○</template>
            </span>
            {{
              autosave === 'saving' ? 'Сохраняется…'
              : autosave === 'saved' ? 'Сохранено'
              : autosave === 'error' ? 'Ошибка сохранения'
              : 'Нет соединения'
            }}
          </span>
        </p>
      </div>

      <div class="flex flex-wrap gap-2">
              <NButton variant="ghost" size="sm" @click="exportSchema">Экспорт JSON</NButton>
              <NButton variant="ghost" size="sm" @click="importJsonClick">Импорт JSON</NButton>
              <NButton v-if="isLocked" variant="secondary" @click="newVersion">Новая версия</NButton>
              <NButton v-else variant="primary" @click="publish">Опубликовать</NButton>
            </div>
    </div>

    <div
          class="mt-5 grid gap-4"
          :class="
            showTools && showInspector ? 'grid-cols-[220px_1fr_320px]'
            : showTools ? 'grid-cols-[220px_1fr]'
            : showInspector ? 'grid-cols-[1fr_320px]'
            : 'grid-cols-1'
          "
        >
          <!-- Инструменты -->
                <aside v-if="showTools" class="space-y-3">
        <div class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle">Инструменты · §47</h2>
          </div>
          <div class="grid grid-cols-2 gap-1 p-2">
            <button
              v-for="item in TOOLS"
              :key="item.value"
              type="button"
              :title="item.hint"
              :disabled="isLocked && item.value !== 'select' && item.value !== 'pan'"
              :class="cn(
                'flex flex-col items-center gap-0.5 rounded-md px-1.5 py-2 text-2xs transition-colors',
                tool === item.value ? 'bg-brand-500/15 text-brand-300 ring-1 ring-brand-500/30' : 'text-muted hover:bg-surface-3 hover:text-content',
                isLocked && item.value !== 'select' && item.value !== 'pan' && 'cursor-not-allowed opacity-40',
              )"
              @click="() => { tool = item.value; if (item.value === 'image') triggerImageUpload() }"
            >
              <span aria-hidden="true" class="text-base leading-none">{{ item.icon }}</span>
              <span class="truncate">{{ item.label }}</span>
            </button>
          </div>
        </div>

        <div class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle">История · §52</h2>
          </div>
          <div class="flex gap-1 p-2">
            <NButton variant="secondary" size="sm" block :disabled="isLocked || history.length === 0" @click="undo">
              ↶ Отменить
            </NButton>
            <NButton variant="secondary" size="sm" block :disabled="isLocked || future.length === 0" @click="redo">
              ↷ Повторить
            </NButton>
          </div>
          <p class="px-3 pb-2 text-2xs text-subtle">
            <kbd class="rounded border border-line px-1 font-mono">Ctrl Z</kbd> · <kbd class="rounded border border-line px-1 font-mono">Ctrl ⇧ Z</kbd>
          </p>
        </div>

        <div class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle">Секторы</h2>
          </div>
          <ul class="max-h-56 divide-y divide-line overflow-y-auto">
            <li v-if="sectors.length === 0" class="px-3 py-4 text-center text-xs text-subtle">Пока пусто</li>
            <li v-for="sector in sectors" :key="sector.id">
              <button
                type="button"
                :class="cn(
                  'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition-colors',
                  selectedSectorId === sector.id ? 'bg-brand-500/10 text-brand-300' : 'text-muted hover:bg-surface-2',
                )"
                @click="selectedSectorId = sector.id; selectedSeatIds = new Set()"
              >
                <span class="truncate">{{ sector.name }}</span>
                <span class="flex-none text-2xs tabular-nums text-subtle">{{ sector.seats.length }}</span>
              </button>
            </li>
          </ul>
        </div>
      </aside>

      <!-- Холст -->
      <section class="min-w-0">
        <div class="surface-card overflow-hidden">
          <div class="flex items-center justify-between border-b border-line px-3 py-2">
            <div class="flex items-center gap-2 text-2xs text-subtle">
              <span class="rounded bg-surface-3 px-1.5 py-0.5">{{ TOOLS.find((t) => t.value === tool)?.label }}</span>
              <span>{{ TOOLS.find((t) => t.value === tool)?.hint }}</span>
            </div>
            <div class="flex gap-1">
                          <button type="button" class="grid h-7 w-7 place-items-center rounded border border-line text-xs text-muted hover:text-content" aria-label="Приблизить" @click="zoomBy(1.15)">+</button>
                          <button type="button" class="grid h-7 w-7 place-items-center rounded border border-line text-xs text-muted hover:text-content" aria-label="Отдалить" @click="zoomBy(0.87)">−</button>
                          <button type="button" class="grid h-7 w-7 place-items-center rounded border border-line text-2xs text-muted hover:text-content" aria-label="Вписать" @click="fit">⤢</button>
                        </div>
                        <div class="flex gap-1">
                          <button
                            type="button"
                            class="grid h-7 rounded border px-2 text-2xs transition-colors"
                            :class="showTools ? 'border-brand-500/40 text-brand-300 bg-brand-500/10' : 'border-line text-muted hover:text-content'"
                            :title="showTools ? 'Скрыть инструменты' : 'Показать инструменты'"
                            @click="showTools = !showTools"
                          >Инструменты</button>
                          <button
                            type="button"
                            class="grid h-7 rounded border px-2 text-2xs transition-colors"
                            :class="showInspector ? 'border-brand-500/40 text-brand-300 bg-brand-500/10' : 'border-line text-muted hover:text-content'"
                            :title="showInspector ? 'Скрыть свойства' : 'Показать свойства'"
                            @click="showInspector = !showInspector"
                          >Свойства</button>
                        </div>
          </div>

          <input ref="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" @change="onImageChosen" />
          <input ref="jsonInput" type="file" accept=".json,application/json" class="hidden" @change="onJsonChosen" />

          <div
            ref="canvasHost"
            :class="cn(
              'h-[680px] w-full bg-surface-2',
              tool === 'pan' ? 'cursor-grab active:cursor-grabbing' : 'cursor-crosshair',
              isLocked && 'cursor-default',
            )"
          />

          <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-3 py-2 text-2xs text-subtle">
            <span>
              Колесо — масштаб · перетаскивание — панорама · <kbd class="rounded border border-line px-1 font-mono">Del</kbd> — удалить · <kbd class="rounded border border-line px-1 font-mono">⇧</kbd>+клик — множественное выделение
            </span>
            <span v-if="isLocked">Опубликованная версия неизменяема</span>
          </div>
        </div>
      </section>

      <!-- Инспектор -->
            <aside v-if="showInspector" class="min-w-0 space-y-3">
        <!-- Генератор сектора (§49) -->
        <div class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">Новый сектор · §49</h2>
            <p class="mt-0.5 text-xs text-subtle">Зал на 400 мест — это четыре числа</p>
          </div>
          <div class="space-y-3 p-3">
            <NInput v-model="form.name" label="Название" placeholder="Партер A" />
            <NSegmented
              v-model="form.shape"
              :segments="[{ value: 'grid', label: 'Прямоугольник' }, { value: 'arc', label: 'Амфитеатр' }]"
              block
              aria-label="Форма рядов"
            />
            <p v-if="form.shape === 'arc'" class="text-2xs text-subtle">
              Места раскладываются по дуге, как в амфитеатре. Угол раствора 180° даёт полукруг.
            </p>
            <div v-if="form.shape === 'arc'" class="grid grid-cols-2 gap-2">
              <NInput v-model.number="form.arcSpread" label="Угол раствора, °" type="number" hint="180 — полукруг" />
            </div>
            <div class="grid grid-cols-2 gap-2">
              <NInput v-model.number="form.rows" label="Рядов" type="number" />
              <NInput v-model.number="form.seatsPerRow" label="Мест в ряду" type="number" />
            </div>
            <NInput v-model.number="form.priceMinor" label="Цена по умолчанию, коп." type="number" hint="Можно переопределить для каждого ряда ниже" />
            <NInput v-model.number="form.vipRows" label="VIP-рядов сверху" type="number" />
            <NButton block :disabled="isLocked" @click="generateSector">Создать сектор</NButton>
          </div>
        </div>

        <!-- Свойства сектора + цены по рядам (§50) -->
        <div v-if="selectedSector" class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">Сектор · {{ selectedSector.name }}</h2>
          </div>
          <div class="space-y-3 p-3">
            <NInput v-model="selectedSector.name" label="Название" :disabled="isLocked" />
            <NInput
              :model-value="String(Math.round(selectedSector.priceMinor / 100))"
              label="Цена по умолчанию, ₽"
              type="number"
              :disabled="isLocked"
              :error="sectorPriceError"
              hint="Целое число рублей; в payload сохраняется в копейках (§50)"
              @update:model-value="setSectorPriceRub"
            />
            <dl class="flex justify-between rounded-lg bg-surface-2 px-3 py-2 text-sm">
              <dt class="text-muted">Мест</dt>
              <dd class="tabular-nums text-content">{{ selectedSector.seats.length }}</dd>
            </dl>
            <details v-if="selectedSector.seats.length" class="text-xs">
              <summary class="cursor-pointer select-none text-muted hover:text-content">Цены по рядам · §50</summary>
              <div class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded bg-surface-2 p-2">
                <div
                  v-for="row in Array.from(new Set(selectedSector.seats.map((s) => s.row))).sort((a, b) => a - b)"
                  :key="row"
                  class="flex items-center justify-between gap-2"
                >
                  <span class="text-subtle">Ряд {{ row }}</span>
                  <input
                    :value="selectedSector.rowPrices[row] != null ? Math.round(selectedSector.rowPrices[row] / 100) : ''"
                    type="number"
                    min="1"
                    step="1"
                    placeholder="—"
                    :disabled="isLocked"
                    :title="rowPriceInputError(row)"
                    :class="cn(
                      'h-8 w-20 rounded-md border bg-surface px-2 text-right text-sm text-content tabular-nums focus:outline-none disabled:opacity-50',
                      rowPriceInputError(row) ? 'border-rose-500' : 'border-line focus:border-brand-400',
                    )"
                    @change="onRowPriceInput(row, ($event.target as HTMLInputElement).value)"
                  />
                </div>
              </div>
            </details>
            <NButton variant="secondary" size="sm" :disabled="isLocked" @click="addRowToSelected">+ Ряд</NButton>
            <NButton variant="danger" block :disabled="isLocked" @click="() => { snapshot(); sectors = sectors.filter((s) => s.id !== selectedSectorId); selectedSectorId = null }">Удалить сектор</NButton>
          </div>
        </div>

        <!-- Свойства места / мультивыделение -->
        <div v-if="selectedSeatIds.size > 0" class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">
              {{ selectedSeatIds.size === 1 ? 'Место' : `Выбрано мест: ${selectedSeatIds.size}` }}
            </h2>
          </div>
          <div class="space-y-3 p-3">
            <template v-if="singleSelectedSeat">
              <div class="grid grid-cols-2 gap-2">
                <NInput :model-value="String(singleSelectedSeat.row)" label="Ряд" type="number" disabled />
                <NInput :model-value="String(singleSelectedSeat.number)" label="Место" type="number" disabled />
              </div>
              <NSelect
                :model-value="singleSelectedSeat?.kind ?? 'standard'"
                :options="KIND_OPTIONS"
                label="Тип места"
                :disabled="isLocked"
                @update:model-value="setSeatKind"
              />
            </template>
            <p v-else class="text-xs text-subtle">Групповые операции действуют на все выделенные места.</p>
            <div class="flex gap-2">
              <NButton variant="secondary" size="sm" block :disabled="isLocked" @click="duplicateSelection">Дублировать</NButton>
              <NButton variant="danger" size="sm" block :disabled="isLocked" @click="deleteSelection">Удалить</NButton>
            </div>
          </div>
        </div>

        <p v-if="!selectedSector && selectedSeatIds.size === 0" class="px-1 text-xs text-subtle">
          Выберите сектор или место на холсте — здесь появятся их свойства
        </p>
      </aside>
    </div>
  </div>
</template>