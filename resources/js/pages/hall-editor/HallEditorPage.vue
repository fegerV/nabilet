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
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Konva from 'konva'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import { useUiStore } from '@/stores/ui'
import { money, plural } from '@/lib/format'
import { buildSeatPricePalette } from '@/lib/hall'
import { cn } from '@/lib/cn'
import { ApiError, get, send } from '@/lib/api'
import type {
  Autosave,
  EBackground,
  ESeat,
  ESector,
  EStatic,
  SeatKind,
  SectorShape,
  StaticKind,
  Tool,
} from './editorTypes'
import {
  GAP,
  ROW_GAP,
  SEAT,
  appendRowToSector,
  arcLayoutParams,
  buildArcSeats,
  buildGridSeats,
  buildTableSeats,
  findFreeSeatPosition,
  rebuildTableSeats,
  tableLayout,
} from './seatGeometry'
import { unwrapSchemaRoot } from './schemaImport'
import {
  bbox,
  danceZoneFor,
  parseSchemaPayload,
  serializeSchema,
  validateSchema,
} from './schemaSerialization'
import { useEditorHistory } from './useEditorHistory'
import {
  clampFloatOrNull,
  clampIntOrNull,
  normalizeRotation,
  requiredNonNegativeError,
  roundOrNull,
  rowPriceError,
  rubToMinor,
} from './hallEditorFields'

/* ── Константы ─────────────────────────────────────────────────────── */

const ui = useUiStore()

/* ── Инструменты (§47) ─────────────────────────────────────────────── */

const TOOLS: { value: Tool; label: string; icon: string; hint: string }[] = [
  { value: 'select', label: 'Выделение', icon: '↖', hint: 'Клик или рамка — выделить' },
  { value: 'pan', label: 'Панорама', icon: '✋', hint: 'Тяните холст, чтобы двигать вид' },
  { value: 'zoom', label: 'Масштаб', icon: '🔍', hint: 'Клик приближает, Alt+клик — отдаляет' },
  { value: 'seat', label: 'Место', icon: '▪', hint: 'Клик в секторе — добавить место' },
  { value: 'row', label: 'Ряд', icon: '▤', hint: 'Добавить ряд к выбранному сектору' },
  { value: 'sector', label: 'Сектор', icon: '▣', hint: 'Тяните прямоугольник — создать сектор' },
  { value: 'table', label: 'Стол', icon: '◫', hint: 'Клик — создать банкетный стол с местами' },
  { value: 'standing', label: 'Standing', icon: '▦', hint: 'Тяните прямоугольник — стоячая зона' },
  { value: 'text', label: 'Текст', icon: 'T', hint: 'Клик — поставить подпись' },
  { value: 'image', label: 'Фон', icon: '🖼', hint: 'Загрузить PNG/JPG/WEBP/SVG под схемой' },
  { value: 'stage', label: 'Сцена', icon: '▬', hint: 'Клик — поставить сцену' },
  { value: 'entrance', label: 'Вход', icon: '↗', hint: 'Клик — поставить вход' },
]

/** Подписи видов статики для панели свойств (§47). */
const STATIC_KIND_LABELS: Record<StaticKind, string> = {
  stage: 'Сцена',
  entrance: 'Вход',
  label: 'Текст',
  text: 'Текст',
  table: 'Стол',
  standing: 'Стоячая зона',
}

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
/** Детали отклонения черновика сервером (422): сообщения валидации по полям (§66). */
const autosaveErrors = ref<string[]>([])
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

/* ── Сведения о зале (§54: город/адрес/описание/фото) ──────────────── */

interface HallDetails {
  name: string
  description: string
  city: string
  address: string
  exterior_photo_url: string
  interior_photo_url: string
}

const hallDetails = ref<HallDetails>({
  name: '',
  description: '',
  city: '',
  address: '',
  exterior_photo_url: '',
  interior_photo_url: '',
})
const hallSaving = ref(false)

function loadHallDetails(): void {
  if (!hallPublicId.value) return
  void get<Partial<HallDetails>>(`/halls/${hallPublicId.value}`)
    .then((res) => {
      const d = res.data
      hallDetails.value = {
        name: d.name ?? hallDetails.value.name,
        description: d.description ?? '',
        city: d.city ?? '',
        address: d.address ?? '',
        exterior_photo_url: d.exterior_photo_url ?? '',
        interior_photo_url: d.interior_photo_url ?? '',
      }
    })
    .catch(() => { /* сведения о зале не критичны для редактора схемы */ })
}

async function saveHallDetails(): Promise<void> {
  if (!hallPublicId.value || hallSaving.value) return
  hallSaving.value = true
  try {
    const payload = {
      name: hallDetails.value.name,
      description: hallDetails.value.description?.trim() || null,
      city: hallDetails.value.city?.trim() || null,
      address: hallDetails.value.address?.trim() || null,
      exterior_photo_url: hallDetails.value.exterior_photo_url?.trim() || null,
      interior_photo_url: hallDetails.value.interior_photo_url?.trim() || null,
    }
    await send(`/halls/${hallPublicId.value}`, 'PUT', payload)
    ui.notify('mint', 'Сведения о зале сохранены')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  } finally {
    hallSaving.value = false
  }
}

/** Реальный id версии и номер ревизии черновика для optimistic locking. */
let currentVersionId: number | null = null
let currentSchemaRevision: number | null = null

/**
 * Ответ сервера на последнюю попытку автосохранения. При 422 (валидация §50)
 * сюда попадают поля ошибки — их показываем в индикаторе, чтобы правка не
 * «молча» терялась на стороне клиента (B9: данные в UI не теряются).
 */
const lastSaveError = ref<string | null>(null)

/** Все текстовые сообщения последней ошибки автосохранения (для показа в шапке). */
watch(autosaveErrors, (list) => {
  lastSaveError.value = list.length > 0 ? list.join('; ') : null
}, { immediate: true })

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

let idCounter = 0
const nextId = (prefix: string): string => `${prefix}-${Date.now().toString(36)}-${(idCounter += 1)}`

const editorHistory = useEditorHistory({ sectors, statics, backgrounds })

const canUndo = computed(() => editorHistory.canUndo())
const canRedo = computed(() => editorHistory.canRedo())

/** Снапшот ДО изменения (см. useEditorHistory). */
function snapshot(): void {
  editorHistory.snapshot()
}

function beginMoveSnapshot(): void {
  editorHistory.beginMoveSnapshot()
}

function endMoveSnapshot(): void {
  editorHistory.endMoveSnapshot()
}

function undo(): void {
  const prev = editorHistory.undo()
  if (!prev) return
  sectors.value = prev.sectors
  statics.value = prev.statics
  backgrounds.value = prev.backgrounds
  selectedSectorId.value = null
  selectedSeatIds.value = new Set()
  selectedStaticIds.value = new Set()
  selectedBgIds.value = new Set()
}

function redo(): void {
  const next = editorHistory.redo()
  if (!next) return
  sectors.value = next.sectors
  statics.value = next.statics
  backgrounds.value = next.backgrounds
}

/* ── Генератор рядов (§49) ─────────────────────────────────────────── */

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
    // радиус растёт на каждый ряд (см. seatGeometry.arcLayoutParams).
    Object.assign(sector, arcLayoutParams(rows, seatsPerRow, arcSpread))
    sector.seats = buildArcSeats(sector, rows, seatsPerRow, vipRows, nextId)
  } else {
    sector.seats = buildGridSeats(rows, seatsPerRow, vipRows, nextId)
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
  if (sector.shape === 'table') {
    ui.notify('sun', 'Стол — это одно кольцо', 'Число мест меняется в свойствах стола (справа), кнопка «+ Ряд» для стола не нужна')
    return
  }
  snapshot()
  const sample = sector.seats[0]
  const seatsPerRow = sample ? sector.seats.filter((s) => s.row === sample.row).length : form.value.seatsPerRow

  // Для дуги appendRowToSector пересчитывает центровку по X и сдвигает
  // старые места, чтобы новый (самый широкий) ряд остался симметричным.
  const { row: nextRow } = appendRowToSector(sector, seatsPerRow, nextId)
  ui.notify('mint', `Ряд ${nextRow} добавлен`, `${seatsPerRow} мест${sector.shape === 'arc' ? ', по дуге' : ''}`)
}

/**
 * Создать банкетный стол с местами (§47): сектор shape='table' с кольцом мест
 * вокруг центра. Продаётся как обычный сектор (все места в ряду 1), поэтому
 * цена стола задаётся одним числом — per-table pricing из коробки.
 */
function createTableAt(x: number, y: number): void {
  snapshot()
  const { seats, cx, cy, ring } = buildTableSeats(8, nextId)
  const sector: ESector = {
    id: nextId('sec'),
    name: `Стол ${sectors.value.length + 1}`,
    priceMinor: form.value.priceMinor,
    x: Math.round(x - cx),
    y: Math.round(y - cy),
    seats,
    rowPrices: {},
    shape: 'table',
    arcSpread: 0,
    arcBaseR: 0,
    arcRowGap: 0,
    arcOffsetX: 0,
    arcOffsetY: 0,
    type: 'seated',
    tableCx: cx,
    tableCy: cy,
    tableRing: ring,
  }
  sectors.value.push(sector)
  selectedSectorId.value = sector.id
  selectedSeatIds.value = new Set()
  selectedStaticIds.value = new Set()
  ui.notify('mint', 'Стол создан', `${sector.name}: 8 мест вокруг стола`)
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

  const freePosition = findFreeSeatPosition(target.seats, localX, localY)
  if (freePosition.y !== localY) {
    newRow = lastRow + 1
  }
  localX = freePosition.x
  localY = freePosition.y

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
function handleToolClick(clientX: number, clientY: number, altKey = false): void {
  // Клик «поверх» завершённого перетаскивания (Konva выдаёт click после
  // mouseup с тем же button) — не должен порождать второй объект.
  if (wasDragged) return
  const t = tool.value
  if (t === 'zoom') {
    zoomAt(altKey ? 1 / 1.12 : 1.12, clientX, clientY)
    return
  }
  if (isLocked.value) return
  if (!EDIT_TOOLS.includes(t) || t === 'image' || t === 'standing') return
  const p = toCanvasPoint(clientX, clientY)
  if (!p) return
  if (t === 'seat') { addSeatAt(p.x, p.y); return }
  if (t === 'table') { createTableAt(p.x, p.y); return }
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
    endMoveSnapshot()
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
  statics.value.push({ id: nextId('static'), kind: 'standing', x, y, width: w, height: h, capacity, priceMinor: form.value.priceMinor, text: `Фан-зона · ${capacity}` })
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
let editRevision = 0
let persistedEditRevision = -1
let persistInFlight: Promise<boolean> | null = null
let isHydratingSchema = false
function scheduleAutosave(): void {
  autosave.value = 'saving'
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    saveTimer = null
    void persistSchema()
  }, 500)
}

/**
 * Собрать схему в формате редактора. Единственная точка сборки payload —
 * и автосохранение, и экспорт в файл идут через неё (см. schemaSerialization),
 * иначе формы неизбежно расходятся.
 */
function buildSchemaPayload(): unknown {
  return serializeSchema({
    canvasSize: canvasSize.value,
    sectors: sectors.value,
    statics: statics.value,
    backgrounds: backgrounds.value,
  })
}

/** Реальное сохранение черновика на сервер. Повторные вызовы сериализуются. */
async function persistSchema(): Promise<boolean> {
  if (!hallPublicId.value || published.value !== null) return false
  if (!navigator.onLine) {
    autosave.value = 'offline'
    return false
  }
  if (persistInFlight) {
    const saved = await persistInFlight
    if (!saved) return false
    return persistedEditRevision >= editRevision ? true : persistSchema()
  }

  const requestedEditRevision = editRevision
  const versionId = currentVersionId
  const payload: Record<string, unknown> = { payload: buildSchemaPayload() }
  if (versionId !== null) {
    payload.version_id = versionId
    if (currentSchemaRevision !== null) payload.base_revision = currentSchemaRevision
  }

  const operation = (async (): Promise<boolean> => {
    try {
      const res = await send<{ id: number; version: number; revision: number }>(
        `/halls/${hallPublicId.value}/schema-versions/draft`,
        'POST',
        payload,
      )
      const d = res.data
      if (typeof d === 'object' && d) {
        if (typeof d.id === 'number') {
          currentVersionId = d.id
          draftVersionId.value = d.id
        }
        if (typeof d.revision === 'number') currentSchemaRevision = d.revision
        if (typeof d.version === 'number' && d.version > 0) draftVersion.value = d.version
      }
      persistedEditRevision = requestedEditRevision
      autosave.value = 'saved'
      autosaveErrors.value = []
      lastSavedAt.value = Date.now()
      return true
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        autosave.value = 'error'
        autosaveErrors.value = describeValidationErrors(e)
      } else {
        autosave.value = navigator.onLine ? 'error' : 'offline'
        autosaveErrors.value = e instanceof Error ? [e.message] : []
      }
      return false
    }
  })()
  let tracked: Promise<boolean>
  tracked = operation.finally(() => {
    if (persistInFlight === tracked) persistInFlight = null
  })
  persistInFlight = tracked
  return tracked
}

/** Отменить debounce и дождаться сохранения именно последнего состояния. */
async function flushAutosave(): Promise<boolean> {
  while (true) {
    if (saveTimer) {
      clearTimeout(saveTimer)
      saveTimer = null
    }
    if (persistedEditRevision >= editRevision && currentVersionId !== null) return true
    if (!await persistSchema()) return false
  }
}

/** Сообщение об ошибке + список деталей валидации из конверта ответа (§66). */
function describeValidationErrors(e: ApiError): string[] {
  const out: string[] = []
  if (e.message) out.push(e.message)
  if (e.details) {
    for (const [field, msgs] of Object.entries(e.details)) {
      for (const m of Array.isArray(msgs) ? msgs : [String(msgs)]) {
        const line = `${field}: ${m}`
        if (!out.includes(line)) out.push(line)
      }
    }
  }
  return out.slice(0, 5)
}

watch(
  [sectors, statics, backgrounds],
  () => {
    if (isHydratingSchema || published.value !== null) return
    editRevision += 1
    scheduleAutosave()
  },
  { deep: true },
)

/* ── Публикация ────────────────────────────────────────────────────── */

const isLocked = computed(() => published.value !== null)

function publish(): void {
  const hasSellableContent = sectors.value.some((sector) => sector.seats.length > 0)
    || statics.value.some((object) => object.kind === 'standing' && (object.capacity ?? 0) > 0)
  if (!hasSellableContent) {
    ui.notify('rose', 'Нечего публиковать', 'Добавьте места или стоячую зону с вместимостью')
    return
  }

  async function doPublish(): Promise<void> {
    try {
      if (!await flushAutosave()) {
        ui.notify('rose', 'Черновик не сохранён', 'Исправьте ошибку автосохранения и повторите публикацию')
        return
      }
      if (!currentVersionId || !hallPublicId.value) {
        ui.notify('rose', 'Черновик не сохранён', 'Не удалось создать черновик на сервере')
        return
      }
      const res = await send<{ id: number; version: number; status: string }>(
        `/halls/${hallPublicId.value}/schema-versions/${currentVersionId}/publish`,
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
    } catch (e) {
      autosave.value = 'error'
      ui.notify('rose', 'Ошибка публикации', e instanceof ApiError ? e.message : 'Не удалось опубликовать схему')
    }
  }
  void doPublish()
}

function newVersion(): void {
  published.value = null
  currentVersionId = null
  currentSchemaRevision = null
  draftVersionId.value = null
  persistedEditRevision = -1
  ui.notify('brand', 'Новый черновик', `Правки попадут в версию ${draftVersion.value}`)
}

/* ── Загрузка схемы с сервера (API) ───────────────────────────────── */

/**
 * Сконвертировать серверную схему в состояние редактора.
 *
 * Вся логика разбора (два формата, нормировка координат, цены по рядам,
 * восстановление подложек и статики) живёт в schemaSerialization.ts — там её
 * можно покрыть юнит-тестами. Здесь остаётся только применение результата к
 * ref'ам компонента.
 */
function applyServerSchema(raw: unknown): void {
  const parsed = parseSchemaPayload(raw, canvasSize.value)

  canvasSize.value = parsed.canvas
  statics.value = parsed.statics
  backgrounds.value = parsed.backgrounds

  if (parsed.sectors.length > 0) {
    sectors.value = parsed.sectors

    // Легаси-схемы «БД-формата» приходят стоячей зоной-сектором без геометрии
    // (все места в одной точке). Дорисовываем её подпись, иначе зал выглядит
    // пустым. Для редакторского формата зона уже есть в staticObjects, поэтому
    // функция возвращает null — импорт остаётся идемпотентным.
    const zone = danceZoneFor(parsed.format, parsed.sectors, parsed.statics, parsed.canvas)
    if (zone) statics.value = [...statics.value, zone]
  } else if (parsed.format === 'empty') {
    // B8: импорт файла БЕЗ секторов обязан очищать схему, а не оставлять
    // старое содержимое с ложным уведомлением «Схема импортирована».
    // Пустой массив — тоже данные: это осознанный «пустой зал».
    sectors.value = []
    selectedSeatIds.value = new Set()
    selectedSectorId.value = null
    selectedStaticIds.value = new Set()
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
  loadHallDetails()
  currentVersionId = null
  currentSchemaRevision = null
  draftVersionId.value = null
  isHydratingSchema = true
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
      currentSchemaRevision = typeof chosen.revision === 'number' ? chosen.revision : null
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
  } finally {
    await nextTick()
    isHydratingSchema = false
    persistedEditRevision = editRevision
  }
}

/* ── Экспорт Schema JSON (§54) ─────────────────────────────────────── */

function exportSchema(): void {
  // Ровно тот же payload, что уходит в автосохранение, — файл и черновик
  // на сервере не могут разойтись.
  const schema = buildSchemaPayload()
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
      let data: unknown
      try {
        data = JSON.parse(reader.result as string)
      } catch {
        ui.notify('rose', 'Ошибка импорта', 'Файл не является корректным JSON')
        return
      }
      // Валидируем СНАЧАЛА — плохой файл не должен затирать рабочую схему
      // и не должен сообщать об успехе (§54: конкретная ошибка по полям).
      const errors = validateSchema(data)
      if (errors) {
        const shown = errors.slice(0, 4).join('; ')
        ui.notify('rose', 'Импорт отклонён', errors.length > 4 ? `${shown} …(+${errors.length - 4})` : shown)
        return
      }
      const before = {
        sectors: JSON.parse(JSON.stringify(sectors.value)) as ESector[],
        statics: JSON.parse(JSON.stringify(statics.value)) as EStatic[],
        backgrounds: JSON.parse(JSON.stringify(backgrounds.value)) as EBackground[],
        canvasSize: { ...canvasSize.value },
        selectedSeatIds: [...selectedSeatIds.value],
        selectedSectorId: selectedSectorId.value,
        selectedStaticIds: [...selectedStaticIds.value],
        selectedBgIds: [...selectedBgIds.value],
        published: published.value,
        loadedPublished: loadedPublished.value,
        draftVersion: draftVersion.value,
        draftVersionId: draftVersionId.value,
        currentVersionId,
        currentSchemaRevision,
        history: editorHistory.history.value.slice(),
        future: editorHistory.future.value.slice(),
      }
      try {
        const root = unwrapSchemaRoot(data)
        if (!root) throw new Error('Файл должен содержать JSON-объект схемы зала')
        snapshot()
        applyServerSchema(root)
        // Метаданные зала из импорта (§54): город/адрес/описание/фото. Не
        // перезаписываем уже заданные значения, чтобы не затереть правки.
        const hallMeta = (root as Record<string, unknown>).hall
        if (hallMeta && typeof hallMeta === 'object') {
          const m = hallMeta as Record<string, unknown>
          const pick = (k: string): string =>
            typeof m[k] === 'string' && (m[k] as string).trim() ? (m[k] as string) : ''
          if (!hallDetails.value.city) hallDetails.value.city = pick('city')
          if (!hallDetails.value.address) hallDetails.value.address = pick('address')
          if (!hallDetails.value.description) hallDetails.value.description = pick('description')
          if (!hallDetails.value.exterior_photo_url) hallDetails.value.exterior_photo_url = pick('exterior_photo_url')
          if (!hallDetails.value.interior_photo_url) hallDetails.value.interior_photo_url = pick('interior_photo_url')
        }
        // Если загружали поверх published — снимем блокировку, чтобы можно было править
        if (published.value !== null) {
          newVersion()
        }
        const count = sectors.value.reduce((acc, s) => acc + s.seats.length, 0)
        ui.notify('mint', 'Схема импортирована', `${sectors.value.length} секторов, ${count} мест`)
      } catch (e) {
        sectors.value = before.sectors
        statics.value = before.statics
        backgrounds.value = before.backgrounds
        canvasSize.value = before.canvasSize
        selectedSeatIds.value = new Set(before.selectedSeatIds)
        selectedSectorId.value = before.selectedSectorId
        selectedStaticIds.value = new Set(before.selectedStaticIds)
        selectedBgIds.value = new Set(before.selectedBgIds)
        published.value = before.published
        loadedPublished.value = before.loadedPublished
        draftVersion.value = before.draftVersion
        draftVersionId.value = before.draftVersionId
        currentVersionId = before.currentVersionId
        currentSchemaRevision = before.currentSchemaRevision
        editorHistory.history.value = before.history
        editorHistory.future.value = before.future
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
let stage: Konva.Stage | null = null
let bgLayer: Konva.Layer | null = null
let staticLayer: Konva.Layer | null = null
let sectorLayer: Konva.Layer | null = null
let guidesLayer: Konva.Layer | null = null
let selectionLayer: Konva.Layer | null = null
let observer: ResizeObserver | null = null

const COLORS = {
  standard: '#24B8E8',
  vip: '#F5B417',
  accessible: '#2AA3FF',
  selected: '#6D4AFF',
  stage: '#F0F2F5',
}

const seatPriceColors = computed(() => buildSeatPricePalette(
  sectors.value.flatMap((sector) => sector.seats.map((seat) => seat.priceMinor ?? sector.rowPrices[seat.row] ?? sector.priceMinor)),
))
const seatPriceLegend = computed(() => [...seatPriceColors.value].map(([price, color]) => ({ price, color })))

function seatFill(seat: ESeat, sector: ESector): string {
  const price = seat.priceMinor ?? sector.rowPrices[seat.row] ?? sector.priceMinor
  return seatPriceColors.value.get(price) ?? COLORS[seat.kind]
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
        endMoveSnapshot()
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
          endMoveSnapshot()
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
    const stOpacity = clampFloatOrNull(s.opacity, 0, 1) ?? 1
    if (s.kind === 'entrance') {
      nodes.push(new Konva.Arrow({ points: [0, 0, 24, -18], pointerLength: 8, pointerWidth: 8, fill: '#A5F5DE', stroke: '#00C48C', strokeWidth: 2, opacity: stOpacity }))
      if (s.text) nodes.push(new Konva.Text({ x: 28, y: -8, text: s.text, fontSize: 11, fill: '#A5F5DE', listening: false, opacity: stOpacity }))
    } else if (s.kind === 'label' && s.text) {
      nodes.push(new Konva.Text({ text: s.text, fontSize: 14, fill: '#B0A0FF', opacity: stOpacity }))
    } else if (s.kind === 'table') {
      nodes.push(new Konva.Rect({ width: s.width ?? 44, height: s.height ?? 32, cornerRadius: 10, fill: '#F4F5F7', stroke: '#D5D9E0', strokeWidth: 1, opacity: stOpacity }))
      nodes.push(new Konva.Text({ y: (s.height ?? 32) / 2 - 6, width: s.width ?? 44, align: 'center', text: 'Стол', fontSize: 10, fill: '#626B78', listening: false, opacity: stOpacity }))
    } else if (s.kind === 'stage') {
      nodes.push(new Konva.Rect({
        width: s.width ?? 260,
        height: s.height ?? 34,
        cornerRadius: [4, 4, 16, 16],
        fill: COLORS.stage,
        stroke: '#D5D9E0',
        strokeWidth: 1,
        opacity: stOpacity,
      }))
      nodes.push(new Konva.Text({ y: 10, width: s.width ?? 260, align: 'center', text: s.text || 'СЦЕНА', fontSize: 12, fontStyle: 'bold', letterSpacing: 2, fill: '#596273', listening: false, opacity: stOpacity }))
    } else if (s.kind === 'standing' && s.width && s.height) {
      nodes.push(new Konva.Rect({ width: s.width, height: s.height, fill: 'rgba(234,237,241,0.72)', stroke: '#AEB5C0', strokeWidth: 1.5, dash: [7, 5], opacity: stOpacity }))
      if (s.text) nodes.push(new Konva.Text({ x: -40, y: -22, width: s.width + 80, align: 'center', text: s.text, fontSize: 13, fontStyle: 'bold', fill: '#596273', listening: false, opacity: stOpacity }))
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
    // Двойной клик — снять выделение (быстрый выход из панели свойств).
    group.on('dblclick', (e) => {
      e.cancelBubble = true
      selectedStaticIds.value = new Set()
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
      // Разрешаем следующий снапшот «до» для нового перемещения — иначе
      // флаг остался бы true навсегда и история перестала бы фиксировать
      // любые последующие drag'и (дефект A8).
      endMoveSnapshot()
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

    // Наименование «Столы на танцполе» не делает сектор standing: столы остаются
    // продаваемыми местами, а овал рисуем только для настоящей или вырожденной зоны.
    const isTableSector = sector.shape === 'table' || sector.name.toLocaleLowerCase('ru').includes('стол')
    const seatsCollapsed = sector.seats.length > 1
      && sector.seats.every((seat) => seat.x === sector.seats[0].x && seat.y === sector.seats[0].y)
    const isDanceZone = !isTableSector && sector.seats.length > 0 && (
      sector.type === 'standing' || /^(танцпол|dance(?:\s*floor)?)$/i.test(sector.name.trim()) || seatsCollapsed
    )
    if (isDanceZone) {
      const cx = sector.seats[0].x + SEAT / 2
      const cy = sector.seats[0].y + SEAT / 2
      const R = 30 + Math.min(sector.seats.length, 10) * 3
      group.add(new Konva.Ellipse({
        x: cx,
        y: cy,
        radiusX: R,
        radiusY: R * 0.6,
        fill: 'rgba(234,237,241,0.72)',
        stroke: '#AEB5C0',
        strokeWidth: 1,
        dash: [6, 4],
        listening: false,
      }))
      group.add(new Konva.Text({
        x: cx + R + 6,
        y: cy - 6,
        text: `Танцпол · ${sector.seats.length} мест`,
        fontSize: 11,
        fill: '#596273',
        listening: false,
      }))
    }

            // Банкетный стол: рисуем «крышку» стола по центру кольца мест.
            if (sector.shape === 'table') {
              const t = tableLayout(sector)
              const topWidth = Math.max(12, t.ring * 0.46)
              const topHeight = Math.max(9, t.ring * 0.34)
              group.add(new Konva.Ellipse({
                x: t.cx,
                y: t.cy,
                radiusX: topWidth,
                radiusY: topHeight,
                fill: '#F4F5F7',
                stroke: '#D5D9E0',
                strokeWidth: 1,
                listening: false,
              }))
              const tableNumber = sector.name.match(/стол\s*№?\s*(\d+)/i)?.[1]
              if (tableNumber) {
                group.add(new Konva.Text({
                  x: t.cx - topWidth,
                  y: t.cy - 6,
                  width: topWidth * 2,
                  text: tableNumber,
                  align: 'center',
                  fontSize: 11,
                  fontStyle: 'bold',
                  fill: '#626B78',
                  listening: false,
                }))
              }
            }

            for (const seat of sector.seats) {
              if (isDanceZone) break
              const isSelected = selectedSeatIds.value.has(seat.id)
      const rect = new Konva.Circle({
        id: seat.id,
        name: 'seat',
        x: seat.x + SEAT / 2,
        y: seat.y + SEAT / 2,
        radius: SEAT / 2 - 1,
        fill: isSelected ? COLORS.selected : seatFill(seat, sector),
        stroke: isSelected ? '#FFFFFF' : 'rgba(22,35,56,0.18)',
        strokeWidth: isSelected ? 1.5 : 1,
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
        const nx = Math.round(rect.x() - SEAT / 2)
        const ny = Math.round(rect.y() - SEAT / 2)
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
        endMoveSnapshot()
        seatDragIds = new Set()
        seatDragBefore.clear()
      })
      rect.on('mouseenter', () => { if (stage) stage.container().style.cursor = 'pointer' })
            rect.on('mouseleave', () => { if (stage) stage.container().style.cursor = 'default' })

            group.add(rect)

            // Номера показываем только у выделенного места: плотные карты остаются
            // читаемыми на общем плане, но точный номер виден при работе с местом.
            if (isSelected) {
              group.add(new Konva.Text({
                x: seat.x - 8,
                y: seat.y + SEAT + 2,
                width: SEAT + 16,
                text: String(seat.number),
                fontSize: 9,
                fill: '#475569',
                align: 'center',
                listening: false,
              }))
            }
          }

    group.on('click', (e) => {
      if (tool.value !== 'select') return
      if (e.target === group || (e.target as Konva.Node).name() === 'sector-label') selectedSectorId.value = sector.id
    })
    group.on('dragstart', () => { beginMoveSnapshot() })
    group.on('dragend', () => {
      sector.x = Math.round(group.x())
      sector.y = Math.round(group.y())
      endMoveSnapshot()
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

function zoomBy(factor: number): void {
  if (!stage) return
  const next = Math.min(2.4, Math.max(0.4, stage.scaleX() * factor))
  stage.scale({ x: next, y: next })
  stage.batchDraw()
}

/** Масштабирование вокруг точки клика: указанная точка холста остаётся под курсором. */
function zoomAt(factor: number, clientX: number, clientY: number): void {
  if (!stage) return
  const bounds = stage.container().getBoundingClientRect()
  const pointer = { x: clientX - bounds.left, y: clientY - bounds.top }
  const oldScale = stage.scaleX() || 1
  const next = Math.min(2.4, Math.max(0.4, oldScale * factor))
  const pointInCanvas = {
    x: (pointer.x - stage.x()) / oldScale,
    y: (pointer.y - stage.y()) / oldScale,
  }
  stage.scale({ x: next, y: next })
  stage.position({
    x: pointer.x - pointInCanvas.x * next,
    y: pointer.y - pointInCanvas.y * next,
  })
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
    handleToolClick(e.evt.clientX, e.evt.clientY, e.evt.altKey)
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

watch([sectors, statics, backgrounds, selectedSeatIds, selectedSectorId, tool], () => {
  if (stage) stage.draggable(tool.value === 'pan')
  draw()
}, { deep: true })

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

/** Целевой сектор для переназначения выделенных мест (§54). */
const reassignTarget = ref<string>('')
/** Сектора, в которые можно перенести (кроме текущего). */
const reassignOptions = computed(() =>
  sectors.value
    .filter((s) => s.id !== selectedSectorId.value)
    .map((s) => ({ value: s.id, label: s.name })),
)
/** Цена (₽) первого выделенного места — для группового поля «Цена всех выбранных». */
const groupSeatPriceRub = computed(() => {
  for (const sector of sectors.value) {
    for (const seat of sector.seats) {
      if (selectedSeatIds.value.has(seat.id)) {
        return (seat.priceMinor ?? sector.rowPrices[seat.row] ?? sector.priceMinor) / 100
      }
    }
  }
  return 0
})

/* ── Валидация числовых полей (§50: цены в копейках) ──────────────────
 * Нечисло / NaN / отрицательное значение / ноль для цены — ошибка: поле
 * подсвечивается, значение НЕ попадает в модель и, соответственно, в
 * payload автосохранения. Границы — как на сервере (HallService::
 * validateSchemaPayload): цена ≥ 1 копейки, ряд/место ≥ 1.
 *
 * Сами правила (rowPriceError, requiredNonNegativeError, rubToMinor,
 * clampIntOrNull, clampFloatOrNull, normalizeRotation) вынесены в
 * ./hallEditorFields — чистый модуль без Vue и состояния, покрытый vitest.
 * Здесь остаются только сеттеры: снимок истории + запись в модель.
 */

function setSectorName(value: string): void {
  const sector = selectedSector.value
  if (!sector || isLocked.value || sector.name === value) return
  snapshot()
  sector.name = value
}

function setSectorPriceRub(v: unknown): void {
  const sector = selectedSector.value
  if (!sector || isLocked.value) return
  const next = rubToMinor(v)
  if (next === null || sector.priceMinor === next) return
  snapshot()
  sector.priceMinor = next
}

/** Живая ошибка для поля «Цена сектора» в инспекторе (§50). */
const sectorPriceError = computed<string | undefined>(() => {
  const s = selectedSector.value
  if (!s) return undefined
  return rowPriceError(s.priceMinor / 100)
})

/** Число мест за банкетным столом (инспектор): 1..60, центр стола сохраняется. */
function setTableSeatCount(sector: ESector, raw: unknown): void {
  if (isLocked.value || sector.shape !== 'table') return
  const n = clampIntOrNull(Math.floor(Number(raw) || 1), 1, 60) ?? 1
  snapshot()
  rebuildTableSeats(sector, n, nextId)
  selectedSectorId.value = sector.id
}

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
  if (rubToMinor(raw) === null) {
    ui.notify('rose', 'Некорректная цена ряда', 'Цена должна быть числом больше нуля')
    return
  }
  setRowPrice(row, Number(raw))
}

/** Индивидуальная цена ряда в рублях; пустая строка снимает переопределение (§50). */
function setRowPrice(row: number, rub: number | '' | null): void {
  const sector = selectedSector.value
  if (!sector || isLocked.value) return
  if (rub === '' || rub === null || Number.isNaN(Number(rub))) {
    if (sector.rowPrices[row] === undefined) return
    snapshot()
    delete sector.rowPrices[row]
    return
  }
  const next = rubToMinor(rub)
  if (next === null || sector.rowPrices[row] === next) return
  snapshot()
  sector.rowPrices[row] = next
}

/** Цена сектора генератора (форма «Новый сектор», коп.): только валидные значения. */
function setFormPriceMinor(v: unknown): void {
  const n = roundOrNull(v)
  if (n === null || n < 0) return
  form.value.priceMinor = n
}

/** Целочисленное поле формы-генератора с нижней границей (ряды, места, VIP). */
function clampIntField<K extends 'rows' | 'seatsPerRow' | 'vipRows' | 'arcSpread'>(key: K, v: unknown, min: number, max: number): void {
  const n = clampIntOrNull(v, min, max)
  if (n !== null) form.value[key] = n
}

/** Размер/поворот/вместимость статики: валидируем до записи в модель. */
function setStaticNum(s: EStatic, key: 'x' | 'y' | 'width' | 'height' | 'rotation' | 'capacity', v: unknown, min: number, max: number): void {
  if (isLocked.value) return
  const next = clampIntOrNull(v, min, max)
  if (next === null || s[key] === next) return
  snapshot()
  ;(s[key] as number) = next
}

function setStaticText(s: EStatic, value: string): void {
  if (isLocked.value || s.text === value) return
  snapshot()
  s.text = value
}

function setStandingPriceRub(s: EStatic, raw: unknown): void {
  if (isLocked.value || s.kind !== 'standing') return
  const next = rubToMinor(raw)
  if (next === null || s.priceMinor === next) return
  snapshot()
  s.priceMinor = next
}

function setStaticOpacity(s: EStatic, value: unknown): void {
  if (isLocked.value) return
  const next = clampFloatOrNull(value, 0, 1)
  if (next === null || (s.opacity ?? 1) === next) return
  snapshot()
  s.opacity = next
}

/** Свойства фона (масштаб/поворот/прозрачность/позиция), §51. */
function setBackgroundNum(bg: EBackground, key: 'x' | 'y' | 'width' | 'height' | 'rotation' | 'opacity', v: unknown): void {
  if (isLocked.value || bg.locked) return
  const n = Number(v)
  if (!Number.isFinite(n)) return
  let next: number
  if (key === 'opacity') {
    next = clampFloatOrNull(n, 0, 1) ?? n
  } else if (key === 'width' || key === 'height') {
    next = clampIntOrNull(n, 1, 20000) ?? n
  } else if (key === 'rotation') {
    next = normalizeRotation(n)
  } else {
    next = roundOrNull(n) ?? n
  }
  if (bg[key] === next) return
  snapshot()
  bg[key] = next
}

function toggleBackgroundLocked(): void {
  const bg = selectedBackground.value
  if (!bg) return
  snapshot()
  bg.locked = !bg.locked
}

/** Блокировка выбранной статики (защита от случайного перетаскивания). */
function toggleStaticLocked(): void {
  const s = selectedStatic.value
  if (!s) return
  snapshot()
  s.locked = !s.locked
}

/** Удалить выбранный статический объект из панели свойств. */
function removeSelectedStatic(): void {
  if (isLocked.value) return
  if (selectedStaticIds.value.size === 0) return
  deleteSelection()
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

/**
 * Индивидуальная цена выделенных мест (минорные единицы). `null` снимает
 * переопределение — место снова берёт цену ряда/сектора. Работает и для
 * группового выделения (на все выбранные места сразу).
 */
function setSeatPriceRub(value: number | null): void {
  if (selectedSeatIds.value.size === 0) return
  snapshot()
  for (const sector of sectors.value) {
    for (const seat of sector.seats) {
      if (selectedSeatIds.value.has(seat.id)) {
        seat.priceMinor = value == null || !Number.isFinite(value) || value <= 0 ? null : Math.round(value * 100)
      }
    }
  }
}

/**
 * Переназначить выделенные места в другой сектор (§54: «переназначать места»).
 * Координаты мест переводятся так, чтобы они сохранили положение на холсте,
 * а не «прыгнули» к нулю целевого сектора. id пересоздаётся, чтобы не было
 * коллизий с uuid сектора-источника.
 */
function reassignSelectedSeats(targetSectorId: string): void {
  if (selectedSeatIds.value.size === 0) return
  const target = sectors.value.find((s) => s.id === targetSectorId)
  if (!target) return
  snapshot()
  const moving: ESeat[] = []
  for (const sector of sectors.value) {
    if (sector.id === targetSectorId) continue
    const kept: ESeat[] = []
    for (const seat of sector.seats) {
      if (selectedSeatIds.value.has(seat.id)) {
        const dx = sector.x - target.x
        const dy = sector.y - target.y
        moving.push({ ...seat, id: nextId('seat'), x: seat.x + dx, y: seat.y + dy })
      } else {
        kept.push(seat)
      }
    }
    sector.seats = kept
  }
  target.seats = [...target.seats, ...moving]
  selectedSectorId.value = targetSectorId
  selectedSeatIds.value = new Set(moving.map((s) => s.id))
  reassignTarget.value = ''
  ui.notify('brand', 'Места переназначены', `${moving.length} → «${target.name}»`)
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
              : autosave === 'error' ? (autosaveErrors.length ? 'Схема отклонена сервером' : 'Ошибка сохранения')
              : 'Нет соединения'
            }}
          </span>
        </p>
        <!-- Детали отклонения черновика сервером (422, конверт §66) — под индикатором. -->
        <ul v-if="autosave === 'error' && autosaveErrors.length > 0" class="mt-1.5 max-w-xl rounded-md border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-2xs text-rose-300">
          <li v-for="(line, i) in autosaveErrors" :key="i">{{ line }}</li>
        </ul>
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
            <NButton variant="secondary" size="sm" block :disabled="isLocked || !canUndo" @click="undo">
              ↶ Отменить
            </NButton>
            <NButton variant="secondary" size="sm" block :disabled="isLocked || !canRedo" @click="redo">
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
          <div v-if="seatPriceLegend.length" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line px-3 py-2" aria-label="Цены мест на схеме">
            <span class="text-2xs font-medium text-subtle">Цены:</span>
            <span v-for="item in seatPriceLegend" :key="item.price" class="flex items-center gap-1.5 text-2xs text-muted">
              <i class="size-2.5 rounded-full" :style="{ backgroundColor: item.color }" /> {{ money(item.price) }}
            </span>
          </div>

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
        <!-- Сведения о зале (§54: город/адрес/описание/фото) -->
        <div class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">Сведения о зале</h2>
            <p class="mt-0.5 text-xs text-subtle">Город, адрес, описание и фото</p>
          </div>
          <div class="space-y-3 p-3">
            <NInput v-model="hallDetails.name" label="Название зала" placeholder="Большой зал" />
            <NInput v-model="hallDetails.city" label="Город" placeholder="Москва" />
            <NInput v-model="hallDetails.address" label="Адрес" placeholder="ул. Тверская, 1" />
            <NInput v-model="hallDetails.description" label="Описание" placeholder="Описание зала" />
            <NInput v-model="hallDetails.exterior_photo_url" label="Фото снаружи (URL)" placeholder="https://…" />
            <NInput v-model="hallDetails.interior_photo_url" label="Фото внутри (URL)" placeholder="https://…" />
            <NButton variant="secondary" block :disabled="hallSaving" @click="saveHallDetails">
              {{ hallSaving ? 'Сохраняем…' : 'Сохранить сведения' }}
            </NButton>
          </div>
        </div>
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
              <NInput :model-value="form.arcSpread" label="Угол раствора, °" type="number" hint="180 — полукруг" @update:model-value="clampIntField('arcSpread', $event, 10, 180)" />
            </div>
            <div class="grid grid-cols-2 gap-2">
              <NInput :model-value="form.rows" label="Рядов" type="number" @update:model-value="clampIntField('rows', $event, 1, 100)" />
              <NInput :model-value="form.seatsPerRow" label="Мест в ряду" type="number" @update:model-value="clampIntField('seatsPerRow', $event, 1, 100)" />
            </div>
            <NInput :model-value="String(form.priceMinor)" label="Цена по умолчанию, коп." type="number" hint="Можно переопределить для каждого ряда ниже" @update:model-value="setFormPriceMinor($event)" />
            <NInput :model-value="form.vipRows" label="VIP-рядов сверху" type="number" @update:model-value="clampIntField('vipRows', $event, 0, 50)" />
            <NButton block :disabled="isLocked" @click="generateSector">Создать сектор</NButton>
          </div>
        </div>

        <!-- Свойства сектора + цены по рядам (§50) -->
        <div v-if="selectedSector" class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">Сектор · {{ selectedSector.name }}</h2>
          </div>
          <div class="space-y-3 p-3">
            <NInput :model-value="selectedSector.name" label="Название" :disabled="isLocked" @update:model-value="setSectorName" />
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
            <template v-if="selectedSector.shape === 'table'">
              <NInput
                :model-value="String(selectedSector.seats.length)"
                label="Мест за столом"
                type="number"
                :min="1"
                :max="60"
                :disabled="isLocked"
                @update:model-value="setTableSeatCount(selectedSector!, $event)"
              />
              <p class="text-2xs text-subtle">Банкетный стол: места по кольцу, одна цена на весь стол.</p>
            </template>
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
              <NInput
                :model-value="String(Math.round((singleSelectedSeat.priceMinor ?? 0) / 100))"
                label="Цена места, ₽"
                type="number"
                :min="0"
                hint="Пусто — цена ряда/сектора"
                :disabled="isLocked"
                :error="rowPriceError((singleSelectedSeat.priceMinor ?? 0) / 100)"
                @update:model-value="(v: string | number) => setSeatPriceRub(String(v).trim() === '' ? null : Number(v))"
              />
            </template>
            <p v-else class="text-xs text-subtle">Групповые операции действуют на все выделенные места.</p>
            <template v-if="selectedSeatIds.size > 1">
              <NInput
                :model-value="String(Math.round((groupSeatPriceRub) / 100))"
                label="Цена всех выбранных, ₽"
                type="number"
                :min="0"
                hint="Установит индивидуальную цену каждому месту"
                :disabled="isLocked"
                @update:model-value="(v: string | number) => setSeatPriceRub(String(v).trim() === '' ? null : Number(v))"
              />
            </template>
            <div v-if="sectors.length > 1">
              <NSelect
                :model-value="reassignTarget"
                :options="reassignOptions"
                label="Переназначить в сектор"
                hint="Переносит выбранные места, сохраняя их положение на холсте"
                :disabled="isLocked"
                @update:model-value="(v: string) => { reassignTarget = v; if (v) reassignSelectedSeats(v) }"
              />
            </div>
            <div class="flex gap-2">
              <NButton variant="secondary" size="sm" block :disabled="isLocked" @click="duplicateSelection">Дублировать</NButton>
              <NButton variant="danger" size="sm" block :disabled="isLocked" @click="deleteSelection">Удалить</NButton>
            </div>
          </div>
        </div>

        <!-- Свойства выбранного статического объекта (§47: stage/entrance/table/text/standing) -->
        <div v-if="selectedStatic" class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">{{ STATIC_KIND_LABELS[selectedStatic.kind] ?? 'Объект' }}</h2>
          </div>
          <div class="space-y-3 p-3">
            <NInput
              :model-value="selectedStatic.text ?? ''"
              label="Подпись"
              :disabled="isLocked"
              @update:model-value="(v: string) => setStaticText(selectedStatic!, v)"
            />
            <div class="grid grid-cols-2 gap-2">
              <NInput
                :model-value="String(Math.round(selectedStatic.x))" label="X, пикс." type="number" :min="0" :max="20000" :disabled="isLocked"
                @update:model-value="(v: string | number) => setStaticNum(selectedStatic!, 'x', v, 0, 20000)"
              />
              <NInput
                :model-value="String(Math.round(selectedStatic.y))" label="Y, пикс." type="number" :min="0" :max="20000" :disabled="isLocked"
                @update:model-value="(v: string | number) => setStaticNum(selectedStatic!, 'y', v, 0, 20000)"
              />
              <NInput
                :model-value="String(Math.round(selectedStatic.width ?? 0))" label="Ширина, пикс." type="number" :min="1" :max="20000" :disabled="isLocked"
                @update:model-value="(v: string | number) => setStaticNum(selectedStatic!, 'width', v, 1, 20000)"
              />
              <NInput
                :model-value="String(Math.round(selectedStatic.height ?? 0))" label="Высота, пикс." type="number" :min="1" :max="20000" :disabled="isLocked"
                @update:model-value="(v: string | number) => setStaticNum(selectedStatic!, 'height', v, 1, 20000)"
              />
              <NInput
                :model-value="String(Math.round(selectedStatic.rotation ?? 0))" label="Поворот, °" type="number" :min="-360" :max="360" :disabled="isLocked"
                @update:model-value="(v: string | number) => setStaticNum(selectedStatic!, 'rotation', v, -360, 360)"
              />
              <NInput
                v-if="selectedStatic.kind === 'standing'"
                :model-value="String(selectedStatic.capacity ?? 0)" label="Вместимость" type="number" :min="1" :max="100000" :disabled="isLocked"
                :error="requiredNonNegativeError(selectedStatic.capacity ?? 0, 'Вместимость')"
                @update:model-value="(v: string | number) => setStaticNum(selectedStatic!, 'capacity', v, 1, 100000)"
              />
              <NInput
                v-if="selectedStatic.kind === 'standing'"
                :model-value="String(Math.round((selectedStatic.priceMinor ?? 0) / 100))"
                label="Цена за место, ₽" type="number" :min="1" :disabled="isLocked"
                :error="rowPriceError((selectedStatic.priceMinor ?? 0) / 100)"
                @update:model-value="(v: string | number) => setStandingPriceRub(selectedStatic!, v)"
              />
            </div>
            <label class="block">
              <span class="mb-1 flex justify-between text-sm font-medium text-content">
                Прозрачность <span class="tabular-nums text-subtle">{{ Math.round((selectedStatic.opacity ?? 1) * 100) }}%</span>
              </span>
              <input
                type="range" min="0" max="1" step="0.05"
                :value="selectedStatic.opacity ?? 1"
                :disabled="isLocked"
                class="w-full accent-brand-500"
                @input="(e) => setStaticOpacity(selectedStatic!, (e.target as HTMLInputElement).value)"
              />
            </label>
            <div class="flex gap-2">
              <NButton variant="secondary" size="sm" block :disabled="isLocked" @click="toggleStaticLocked">
                {{ selectedStatic.locked ? '🔓 Разблокировать' : '🔒 Заблокировать' }}
              </NButton>
              <NButton variant="danger" size="sm" block :disabled="isLocked" @click="removeSelectedStatic">Удалить</NButton>
            </div>
          </div>
        </div>

        <!-- Групповое выделение статики без единственного объекта -->
        <div v-else-if="selectedStaticIds.size > 1" class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">Выбрано объектов: {{ selectedStaticIds.size }}</h2>
          </div>
          <div class="space-y-2 p-3">
            <p class="text-xs text-subtle">Объекты можно перемещать группой. Свойства доступны при одиночном выделении.</p>
            <NButton variant="danger" size="sm" block :disabled="isLocked" @click="deleteSelection">Удалить выбранные</NButton>
          </div>
        </div>

        <!-- Свойства фона (§51): позиция, масштаб, поворот, прозрачность, блокировка -->
        <div v-if="selectedBackground" class="surface-card overflow-hidden">
          <div class="border-b border-line px-3 py-2.5">
            <h2 class="text-sm font-semibold text-content">Фон · изображение</h2>
          </div>
          <div class="space-y-3 p-3">
            <div class="grid grid-cols-2 gap-2">
              <NInput
                :model-value="String(Math.round(selectedBackground.x))" label="X, пикс." type="number" :disabled="isLocked || selectedBackground.locked"
                @update:model-value="(v: string | number) => setBackgroundNum(selectedBackground!, 'x', v)"
              />
              <NInput
                :model-value="String(Math.round(selectedBackground.y))" label="Y, пикс." type="number" :disabled="isLocked || selectedBackground.locked"
                @update:model-value="(v: string | number) => setBackgroundNum(selectedBackground!, 'y', v)"
              />
              <NInput
                :model-value="String(Math.round(selectedBackground.width))" label="Ширина, пикс." type="number" :min="1" :max="20000" :disabled="isLocked || selectedBackground.locked"
                @update:model-value="(v: string | number) => setBackgroundNum(selectedBackground!, 'width', v)"
              />
              <NInput
                :model-value="String(Math.round(selectedBackground.height))" label="Высота, пикс." type="number" :min="1" :max="20000" :disabled="isLocked || selectedBackground.locked"
                @update:model-value="(v: string | number) => setBackgroundNum(selectedBackground!, 'height', v)"
              />
              <NInput
                :model-value="String(Math.round(selectedBackground.rotation))" label="Поворот, °" type="number" :min="-360" :max="360" :disabled="isLocked || selectedBackground.locked"
                @update:model-value="(v: string | number) => setBackgroundNum(selectedBackground!, 'rotation', v)"
              />
            </div>
            <label class="block">
              <span class="mb-1 flex justify-between text-sm font-medium text-content">
                Прозрачность <span class="tabular-nums text-subtle">{{ Math.round(selectedBackground.opacity * 100) }}%</span>
              </span>
              <input
                type="range" min="0" max="1" step="0.05"
                :value="selectedBackground.opacity"
                :disabled="isLocked || selectedBackground.locked"
                class="w-full accent-brand-500"
                @input="(e) => setBackgroundNum(selectedBackground!, 'opacity', (e.target as HTMLInputElement).value)"
              />
            </label>
            <div class="flex gap-2">
              <NButton variant="secondary" size="sm" block :disabled="isLocked" @click="toggleBackgroundLocked">
                {{ selectedBackground.locked ? '🔓 Разблокировать' : '🔒 Заблокировать' }}
              </NButton>
              <NButton variant="danger" size="sm" block :disabled="isLocked" @click="removeBackground">Удалить фон</NButton>
            </div>
          </div>
        </div>

        <p v-if="!selectedSector && selectedSeatIds.size === 0 && selectedStaticIds.size === 0 && !selectedBackground" class="px-1 text-xs text-subtle">
          Выберите сектор, место, объект или фон на холсте — здесь появятся их свойства
        </p>
      </aside>
    </div>
  </div>
</template>