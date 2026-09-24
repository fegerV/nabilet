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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Konva from 'konva'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NBadge from '@/components/ui/NBadge.vue'
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
const selectedStaticId = ref<string | null>(null)

const published = ref<number | null>(null)
const draftVersion = ref(3)
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

function snapshot(): void {
  history.value.push({
    sectors: JSON.parse(JSON.stringify(sectors.value)) as ESector[],
    statics: JSON.parse(JSON.stringify(statics.value)) as EStatic[],
    backgrounds: JSON.parse(JSON.stringify(backgrounds.value)) as EBackground[],
  })
  future.value = []
  if (history.value.length > 50) history.value.shift()
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
  selectedStaticId.value = null
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
  if (tool.value !== 'row') return
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

function pickSeat(seatId: string, sectorId: string, additive: boolean): void {
  if (tool.value !== 'select') return
  if (additive) {
    const next = new Set(selectedSeatIds.value)
    if (next.has(seatId)) next.delete(seatId)
    else next.add(seatId)
    selectedSeatIds.value = next
  } else {
    selectedSectorId.value = sectorId
    selectedSeatIds.value = new Set([seatId])
  }
}

function deleteSelection(): void {
  if (selectedSeatIds.value.size === 0 && !selectedStaticId.value && !selectedSectorId.value) return
  snapshot()
  if (selectedSeatIds.value.size > 0) {
    for (const sector of sectors.value) {
      sector.seats = sector.seats.filter((s) => !selectedSeatIds.value.has(s.id))
    }
    selectedSeatIds.value = new Set()
  } else if (selectedStaticId.value) {
    statics.value = statics.value.filter((s) => s.id !== selectedStaticId.value)
    selectedStaticId.value = null
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
  rows?: Array<{
    number: string | number
    label?: string
    price_amount?: number
    seats?: Array<{ number: string | number; label?: string; type?: string; x?: number; y?: number }>
  }>
  seats?: Array<{ id?: string; row?: number; number?: number; kind?: string; x?: number; y?: number }>
  priceMinor?: number
  shape?: string
}

/** Сконвертировать серверную схему (БД-формат или редакторский JSON) в состояние редактора. */
function applyServerSchema(raw: unknown): void {
  const obj = (typeof raw === 'object' && raw) ? raw as Record<string, unknown> : {}
  const rawCanvas = (typeof obj.canvas === 'object' && obj.canvas)
    ? obj.canvas as Record<string, unknown>
    : null
  if (rawCanvas) {
    const w = Number(rawCanvas.width ?? canvasSize.value.width)
    const h = Number(rawCanvas.height ?? canvasSize.value.height)
    if (w > 0 && h > 0) canvasSize.value = { width: w, height: h }
  }
  const rawSectors = Array.isArray(obj.sectors) ? obj.sectors : []

  // Сначала собираем ВСЕ секторы (канвас или БД-формат), чтобы нормализовать
  // координаты ОБЩИМ диапазоном по всему залу — иначе каждый сектор
  // масштабируется в свой угол и схема разъезжается.
  const collected: { sector: ServerSector; seats: { row: number; number: number; x: number; y: number; kind: string }[] }[] = []
  let allMinX = Infinity, allMaxX = -Infinity, allMinY = Infinity, allMaxY = -Infinity
  for (const rs of rawSectors) {
    const s = (typeof rs === 'object' && rs) ? rs as ServerSector : null
    if (!s || !s.name) continue
    let seats: { row: number; number: number; x: number; y: number; kind: string }[] = []
    if (Array.isArray(s.seats)) {
      seats = s.seats.map((seat) => ({
        row: Number(seat.row ?? 0),
        number: Number(seat.number ?? 0),
        kind: (seat.kind === 'vip' || seat.kind === 'accessible') ? seat.kind : 'standard',
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
            kind: 'standard',
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

  // Общий масштаб: вся схема влезает в канвас целиком.
  const pad = 40
  const spanX = (allMaxX - allMinX) || 1
  const spanY = (allMaxY - allMinY) || 1
  const scale = Math.min(
    (canvasSize.value.width - pad * 2) / spanX,
    (canvasSize.value.height - pad * 2) / spanY,
    60,
  )

  const out: ESector[] = []
    for (const { sector: s, seats: flatSeats } of collected) {
      const seats: ESector['seats'] = flatSeats.map((p) => ({
        id: `${s.name}-${p.row}-${p.number}`,
        row: p.row,
        number: p.number,
        kind: (p.kind === 'vip' || p.kind === 'accessible') ? p.kind : 'standard',
        x: Math.round(pad + (p.x - allMinX) * scale),
        y: Math.round(pad + (p.y - allMinY) * scale),
      }))
    out.push({
      id: `s${out.length + 1}`,
      name: s.name,
      priceMinor: Number(s.priceMinor ?? 0),
      x: 0,
      y: 0,
      seats,
      rowPrices: {},
      shape: (s.shape === 'arc') ? 'arc' : 'grid',
      arcSpread: 120,
      arcBaseR: 200,
      arcRowGap: 24,
      arcOffsetX: 0,
      arcOffsetY: 0,
    })
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
              // Зону танцпола рисуем по центру под сценой (у Яндекса координата y
              // растёт вниз, и зона оказывается у нижнего края — визуально неверно).
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
        draftVersion.value = versionNo + 1
      } else {
        draftVersion.value = versionNo > 0 ? versionNo : draftVersion.value
        draftVersionId.value = Number(chosen.id ?? null)
      }
      const schema = chosen.schema
            if (schema) { applyServerSchema(schema); fit() }
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

  // Слой 1: фон-изображение
  for (const bg of backgrounds.value) {
    const img = new Image()
    img.onload = () => {
      bgLayer?.add(new Konva.Image({ image: img, x: bg.x, y: bg.y, width: bg.width, height: bg.height, opacity: bg.opacity, rotation: bg.rotation }))
      bgLayer?.draw()
    }
    img.src = bg.src
  }

  // Слой 2: статические объекты (§46: static_objects)
  const stageRect = new Konva.Rect({
    x: canvasSize.value.width / 2 - 130,
    y: 24,
    width: 260,
    height: 34,
    cornerRadius: [4, 4, 16, 16],
    fillLinearGradientStartPoint: { x: 0, y: 0 },
    fillLinearGradientEndPoint: { x: 260, y: 0 },
    fillLinearGradientColorStops: [0, '#6D4AFF', 1, '#FF5C22'],
  })
  staticLayer?.add(stageRect)
  staticLayer?.add(
    new Konva.Text({
      x: canvasSize.value.width / 2 - 130,
      y: 34,
      width: 260,
      align: 'center',
      text: 'СЦЕНА',
      fontSize: 12,
      fontStyle: 'bold',
      letterSpacing: 3,
      fill: '#FFFFFF',
    }),
  )
  for (const s of statics.value) {
      if (s.kind === 'entrance') {
      const arrow = new Konva.Arrow({ points: [s.x, s.y, s.x + 24, s.y - 18], pointerLength: 8, pointerWidth: 8, fill: '#A5F5DE', stroke: '#00C48C', strokeWidth: 2 })
      staticLayer?.add(arrow)
    } else if (s.kind === 'label' && s.text) {
      staticLayer?.add(new Konva.Text({ x: s.x, y: s.y, text: s.text, fontSize: 14, fill: '#B0A0FF' }))
    } else if (s.kind === 'table') {
      staticLayer?.add(new Konva.Rect({ x: s.x, y: s.y, width: 44, height: 32, cornerRadius: 6, fill: '#3B3468', stroke: '#8E74FF', strokeWidth: 1 }))
    } else if (s.kind === 'standing' && s.width && s.height) {
          staticLayer?.add(new Konva.Rect({ x: s.x, y: s.y, width: s.width, height: s.height, fill: 'rgba(58,50,112,0.55)', stroke: '#C9A0FF', strokeWidth: 2, dash: [8, 5] }))
          if (s.text) {
            staticLayer?.add(new Konva.Text({ x: s.x - 40, y: s.y - 22, width: s.width + 80, align: 'center', text: s.text, fontSize: 15, fontStyle: 'bold', fill: '#FFFFFF' }))
          }
        }
  }

  // Слой 3: секторы + места (§46: sectors, rows, seats)
    for (const sector of sectors.value) {
      if (typeof console !== 'undefined') console.log('[hall-editor] сектор', sector.id, sector.name, 'мест:', sector.seats.length)
      const group = new Konva.Group({
      id: sector.id,
      x: sector.x,
      y: sector.y,
      draggable: published.value === null && tool.value !== 'pan',
    })

    group.add(
      new Konva.Text({
        y: -20,
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
        x: seat.x,
        y: seat.y,
        width: SEAT,
        height: SEAT,
        cornerRadius: 5,
        fill: isSelected ? COLORS.selected : seatFill(seat),
        stroke: isSelected ? '#B0A0FF' : undefined,
        strokeWidth: isSelected ? 1 : 0,
      })

      rect.on('click', (e) => {
        if (tool.value !== 'select') return
        e.cancelBubble = true
        pickSeat(seat.id, sector.id, e.evt.shiftKey)
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
      if (e.target === group) selectedSectorId.value = sector.id
    })
    group.on('dragend', () => {
      sector.x = Math.round(group.x())
      sector.y = Math.round(group.y())
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

onMounted(() => {
  void loadFromServer()
  if (!canvasHost.value) return
  canvasSize.value = { width: canvasHost.value.clientWidth, height: canvasHost.value.clientHeight }

  stage = new Konva.Stage({
    container: canvasHost.value,
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

  stage.on('wheel', (e) => {
    e.evt.preventDefault()
    zoomBy(e.evt.deltaY < 0 ? 1.12 : 0.9)
  })

  observer = new ResizeObserver((entries) => {
    const rect = entries[0]?.contentRect
    if (!rect || !stage) return
    canvasSize.value = { width: rect.width, height: rect.height }
    stage.size(canvasSize.value)
    draw()
  })
  observer.observe(canvasHost.value)

  // Демо-данные: амфитеатр + прямоугольный партер, чтобы холст не был пустым.
  form.value = { name: 'Балкон', shape: 'arc', rows: 6, seatsPerRow: 16, priceMinor: 1200000, vipRows: 1, arcSpread: 140 }
  generateSector()
  const balcony = sectors.value[0]
  balcony.x = 80
  balcony.y = 110

  form.value = { name: 'Партер A', shape: 'grid', rows: 7, seatsPerRow: 18, priceMinor: 850000, vipRows: 2, arcSpread: 160 }
  generateSector()
  const parterre = sectors.value[1]
  parterre.x = 80
  parterre.y = balcony.y + bbox(balcony).height + 70
  draw()
})

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
const totalSeats = computed(() => sectors.value.reduce((sum, s) => sum + s.seats.length, 0))

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

function setRowPrice(row: number, rub: number): void {
  if (!selectedSector.value) return
  snapshot()
  selectedSector.value.rowPrices[row] = Math.round(rub * 100)
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
              @update:model-value="(v) => (selectedSector!.priceMinor = Number(v) * 100)"
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
                    :value="Math.round((selectedSector.rowPrices[row] ?? selectedSector.priceMinor) / 100)"
                    type="number"
                    :disabled="isLocked"
                    class="h-8 w-20 rounded-md border border-line bg-surface px-2 text-right text-sm text-content tabular-nums focus:border-brand-400 focus:outline-none disabled:opacity-50"
                    @input="setRowPrice(row, Number(($event.target as HTMLInputElement).value))"
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