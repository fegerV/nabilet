/**
 * Сериализация и разбор схем залов — единственный источник правды.
 *
 * Раньше в HallEditorPage.vue было ДВА независимых литерала одной и той же
 * формы payload (`buildSchemaPayload` для автосохранения и `exportSchema` для
 * файла) и один большой разбор `applyServerSchema`. Любая правка формата
 * требовала синхронно менять три места, поэтому экспорт и автосохранение
 * неизбежно расходились, а проверить round-trip юнит-тестом было нельзя —
 * функции жили внутри компонента и зависели от его ref'ов.
 *
 * Здесь всё это вынесено в чистые функции: состояние передаётся аргументом,
 * зависимость от размера холста — явный параметр, никаких обращений к Vue.
 * Компонент остаётся тонкой обёрткой (ref → аргумент, результат → ref).
 */
import type { EBackground, ESector, ESeat, EStatic, SeatKind, SectorShape, StaticKind } from './editorTypes'
import { SEAT, GAP, ROW_GAP, tableLayout } from './seatGeometry'
import { unwrapSchemaRoot } from './schemaImport'

/** Версия формата схемы (§54). */
export const SCHEMA_VERSION = '1.0'

export interface SchemaCanvas {
  width: number
  height: number
}

export interface SerializedSeat {
  id: string
  row: number
  number: number
  kind: SeatKind
  x: number
  y: number
}

export interface SerializedSector {
  id: string
  name: string
  x: number
  y: number
  priceMinor: number
  type: 'seated' | 'standing' | 'mixed'
  rowPrices: Record<number, number>
  shape: SectorShape
  arcSpread: number
  arcBaseR: number
  arcRowGap: number
  arcOffsetX: number
  arcOffsetY: number
  seats: SerializedSeat[]
}

export interface SerializedSchema {
  version: string
  canvas: SchemaCanvas
  /**
   * Первая подложка — для совместимости: старые потребители (и серверная
   * колонка background_url) знают только про одну картинку.
   */
  background: EBackground | null
  /**
   * ВСЕ подложки (§51 допускает несколько картинок на холсте). Раньше в
   * payload уезжала только `backgrounds[0]`, поэтому вторая и последующие
   * картинки молча исчезали после автосохранения и F5.
   */
  backgrounds: EBackground[]
  sectors: SerializedSector[]
  staticObjects: EStatic[]
}

/** Состояние редактора, из которого собирается payload. */
export interface EditorSchemaState {
  canvasSize: SchemaCanvas
  sectors: ESector[]
  statics: EStatic[]
  backgrounds: EBackground[]
}

/**
 * Собрать payload схемы. Используется И автосохранением, И экспортом в файл —
 * ровно одна форма, поэтому файл и черновик на сервере всегда совпадают.
 */
export function serializeSchema(state: EditorSchemaState): SerializedSchema {
  return {
    version: SCHEMA_VERSION,
    canvas: { width: state.canvasSize.width, height: state.canvasSize.height },
    background: state.backgrounds[0] ?? null,
    backgrounds: state.backgrounds.map((bg) => ({ ...bg })),
    sectors: state.sectors.map((s) => ({
      id: s.id,
      name: s.name,
      x: s.x,
      y: s.y,
      priceMinor: s.priceMinor,
      type: s.type ?? 'seated',
      rowPrices: { ...s.rowPrices },
      shape: s.shape,
      arcSpread: s.arcSpread,
      arcBaseR: s.arcBaseR,
      arcRowGap: s.arcRowGap,
      arcOffsetX: s.arcOffsetX,
      arcOffsetY: s.arcOffsetY,
      // Геометрия банкетного стола (tableCx/tableCy/tableRing) НЕ пишется
      // намеренно: это производная от мест величина (см. tableLayout), и
      // дублировать её в payload — значит получить два источника правды,
      // которые разъедутся. После разбора она пересчитывается.
      seats: s.seats.map((seat) => ({
        id: seat.id,
        row: seat.row,
        number: seat.number,
        kind: seat.kind,
        x: seat.x,
        y: seat.y,
      })),
    })),
    staticObjects: state.statics.map((o) => ({ ...o })),
  }
}

/**
 * Нормализация статического объекта из payload/импорта (B7/B8): приводим
 * разнородный JSON к EStatic, отбрасывая мусор без id/kind.
 */
export function normalizeStaticObject(o: unknown): EStatic | null {
  if (!o || typeof o !== 'object') return null
  const rec = o as Record<string, unknown>
  if (typeof rec.id !== 'string' || typeof rec.kind !== 'string') return null
  const kinds: StaticKind[] = ['table', 'standing', 'label', 'text', 'stage', 'entrance']
  if (!kinds.includes(rec.kind as StaticKind)) return null
  return {
    id: rec.id,
    // Импорт/старые payload'ы могут прислать 'text' вместо канонического 'label'.
    kind: (rec.kind === 'text' ? 'label' : rec.kind) as StaticKind,
    x: Math.round(Number(rec.x ?? 0)),
    y: Math.round(Number(rec.y ?? 0)),
    width: Number.isFinite(Number(rec.width)) ? Number(rec.width) : undefined,
    height: Number.isFinite(Number(rec.height)) ? Number(rec.height) : undefined,
    rotation: Number.isFinite(Number(rec.rotation)) ? Number(rec.rotation) : 0,
    opacity: Number.isFinite(Number(rec.opacity)) ? Math.min(1, Math.max(0, Number(rec.opacity))) : 1,
    locked: rec.locked === true,
    text: typeof rec.text === 'string' ? rec.text : undefined,
    capacity: Number.isFinite(Number(rec.capacity)) ? Math.max(0, Math.round(Number(rec.capacity))) : undefined,
    priceMinor: Number.isFinite(Number(rec.priceMinor ?? rec.price))
      ? Math.max(0, Math.round(Number(rec.priceMinor ?? rec.price)))
      : undefined,
  }
}

/**
 * Нормализация фона из payload (B7): src обязателен, остальное — с дефолтами
 * по размеру холста. Размер холста передаётся явно: раньше функция читала
 * ref компонента, из-за чего её нельзя было протестировать и легко было
 * получить фон нулевого размера при разборе до гидратации холста.
 */
export function normalizeBackground(bg: Record<string, unknown>, canvasSize: SchemaCanvas): EBackground | null {
  if (typeof bg.src !== 'string' || !bg.src) return null
  return {
    id: typeof bg.id === 'string' ? bg.id : 'bg-restored',
    src: bg.src,
    x: Math.round(Number(bg.x ?? 0)),
    y: Math.round(Number(bg.y ?? 0)),
    width: Number(bg.width) > 0 ? Number(bg.width) : canvasSize.width,
    height: Number(bg.height) > 0 ? Number(bg.height) : canvasSize.height,
    rotation: Number.isFinite(Number(bg.rotation)) ? Number(bg.rotation) : 0,
    locked: bg.locked === true,
    opacity: Number.isFinite(Number(bg.opacity)) ? Math.min(1, Math.max(0, Number(bg.opacity))) : 1,
  }
}

/** Габаритный бокс сектора в его локальных координатах. */
export function bbox(sector: ESector): { x: number; y: number; width: number; height: number } {
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

/* ── Разбор payload ─────────────────────────────────────────────────── */

/** Формат, в котором пришла схема. */
export type SchemaFormat = 'editor' | 'db' | 'empty'

export interface NormalizedSchema {
  format: SchemaFormat
  canvas: SchemaCanvas
  sectors: ESector[]
  statics: EStatic[]
  backgrounds: EBackground[]
  /** Сектора, которые пришлось выбросить (нет мест/рядов) — для диагностики. */
  droppedSectors: string[]
}

interface ServerSeat {
  row?: unknown
  number?: unknown
  kind?: unknown
  type?: unknown
  x?: unknown
  y?: unknown
  id?: unknown
  price_amount?: unknown
  price?: unknown
  /** Только для элементов rows[] — места внутри ряда. */
  seats?: unknown
}

interface ServerSector {
  id?: unknown
  name?: unknown
  x?: unknown
  y?: unknown
  priceMinor?: unknown
  price?: unknown
  type?: unknown
  rowPrices?: unknown
  shape?: unknown
  arcSpread?: unknown
  arcBaseR?: unknown
  arcRowGap?: unknown
  arcOffsetX?: unknown
  arcOffsetY?: unknown
  seats?: unknown
  rows?: unknown
}

function num(v: unknown, fallback = 0): number {
  const n = Number(v)
  return Number.isFinite(n) ? n : fallback
}

function toSeatKind(raw: unknown, fromDb: boolean): SeatKind {
  const v = String(raw ?? '')
  if (v === 'vip') return 'vip'
  if (v === 'accessible' || v === 'wheelchair') return 'accessible'
  if (!fromDb && (v === 'standard' || v === 'companion' || v === 'custom')) return 'standard'
  return 'standard'
}

/**
 * Разобрать серверную схему (БД-формат или редакторский JSON) в состояние
 * редактора.
 *
 * Два режима, и это принципиально:
 *  - редакторский JSON (sectors[].seats[] с координатами, локальными для
 *    группы сектора, и полной геометрией дуги) загружается БЕЗ
 *    масштабирования — иначе F5 меняет схему (пересчёт масштаба при каждой
 *    загрузке терял точность и переставлял сектора);
 *  - «БД-формат» (sectors[].rows[].seats[], сидер/импортёр Афиши)
 *    нормализуется к сетке редактора: координаты могут быть битыми (все
 *    нули) — ряды раскладываются по константам SEAT/GAP/ROW_GAP, цены
 *    берутся из row.price_amount (или легаси sector.price).
 *
 * Формат определяется по ПЕРВОМУ сектору с местами. Смешанные файлы
 * (часть секторов в редакторском формате, часть в БД-формате) не
 * поддерживаются — такие сектора читаются по правилам выбранного режима.
 */
export function parseSchemaPayload(raw: unknown, canvasSize: SchemaCanvas): NormalizedSchema {
  // Сервер может вернуть schema_json как JSON-строку (колонка JSON отдаётся
  // драйвером как строка, а не разобранным объектом) — без этого шага весь
  // payload читался бы как {} и статика/фон/цены «терялись» при перезагрузке (B7).
  let input = raw
  if (typeof input === 'string') {
    try { input = JSON.parse(input) } catch { input = null }
  }
  const obj = (typeof input === 'object' && input) ? input as Record<string, unknown> : {}

  const canvas: SchemaCanvas = { ...canvasSize }
  const rawCanvas = (typeof obj.canvas === 'object' && obj.canvas)
    ? obj.canvas as Record<string, unknown>
    : null
  if (rawCanvas) {
    const w = num(rawCanvas.width, canvas.width)
    const h = num(rawCanvas.height, canvas.height)
    if (w > 0 && h > 0) { canvas.width = w; canvas.height = h }
  }

  const statics: EStatic[] = Array.isArray(obj.staticObjects)
    ? (obj.staticObjects as unknown[]).map(normalizeStaticObject).filter((o): o is EStatic => o !== null)
    : []

  // Подложки: сначала новый формат (массив), затем легаси-одиночка.
  const backgrounds: EBackground[] = []
  if (Array.isArray(obj.backgrounds)) {
    for (const b of obj.backgrounds as unknown[]) {
      if (typeof b !== 'object' || !b) continue
      const bg = normalizeBackground(b as Record<string, unknown>, canvas)
      if (bg) backgrounds.push(bg)
    }
  }
  if (backgrounds.length === 0 && typeof obj.background === 'object' && obj.background) {
    const bg = normalizeBackground(obj.background as Record<string, unknown>, canvas)
    if (bg) backgrounds.push(bg)
  }

  const rawSectors = Array.isArray(obj.sectors) ? obj.sectors : []

  interface FlatSeat { row: number; number: number; x: number; y: number; kind: SeatKind }
  const collected: { sector: ServerSector; seats: FlatSeat[] }[] = []
  const droppedSectors: string[] = []
  let allMinX = Infinity, allMaxX = -Infinity, allMinY = Infinity, allMaxY = -Infinity

  for (const rs of rawSectors) {
    const s = (typeof rs === 'object' && rs) ? rs as ServerSector : null
    if (!s || typeof s.name !== 'string' || !s.name) continue
    let seats: FlatSeat[] = []
    if (Array.isArray(s.seats)) {
      seats = (s.seats as ServerSeat[]).map((seat) => ({
        row: num(seat?.row, 0),
        number: num(seat?.number, 0),
        kind: toSeatKind(seat?.kind, false),
        x: num(seat?.x, 0),
        y: num(seat?.y, 0),
      }))
    } else if (Array.isArray(s.rows)) {
      for (const r of s.rows as ServerSeat[]) {
        const rowNo = num(r?.number, 0)
        for (const seat of (r?.seats ?? []) as ServerSeat[]) {
          seats.push({
            row: rowNo,
            number: num(seat?.number, 0),
            kind: toSeatKind(seat?.type, true),
            x: num(seat?.x, 0),
            y: num(seat?.y, 0),
          })
        }
      }
    }
    if (seats.length === 0) {
      // Сектор без мест — «сирота» (только подпись/рамка). Раньше он молча
      // выбрасывался; теперь о нём хотя бы известно вызывающему.
      if (typeof s.name === 'string') droppedSectors.push(s.name)
      continue
    }
    collected.push({ sector: s, seats })
    for (const p of seats) {
      if (p.x < allMinX) allMinX = p.x
      if (p.x > allMaxX) allMaxX = p.x
      if (p.y < allMinY) allMinY = p.y
      if (p.y > allMaxY) allMaxY = p.y
    }
  }

  // Формат определяется по первому сектору с местами: наличие seats[] +
  // отсутствие непустого rows[] ⇒ редакторский JSON.
  const firstWithSeats = collected[0]
  const isEditorFormat = !!firstWithSeats
    && Array.isArray(firstWithSeats.sector.seats)
    && !(Array.isArray(firstWithSeats.sector.rows) && (firstWithSeats.sector.rows as unknown[]).length > 0)

  const out: ESector[] = []

  if (isEditorFormat) {
    for (const { sector: s, seats: flatSeats } of collected) {
      const srcId = typeof s.id === 'string' ? s.id : `s${out.length + 1}`
      const rowPrices: Record<number, number> = {}
      for (const [k, v] of Object.entries((s.rowPrices ?? {}) as Record<string, unknown>)) {
        const rn = Number(k)
        if (rn > 0 && typeof v === 'number' && Number.isFinite(v)) rowPrices[rn] = Math.max(0, Math.round(v))
      }
      const rawSeats = Array.isArray(s.seats) ? s.seats as ServerSeat[] : []
      out.push({
        id: srcId,
        name: s.name as string,
        priceMinor: Math.max(0, Math.round(num(s.priceMinor ?? s.price, 0))),
        x: Math.round(num(s.x, 0)),
        y: Math.round(num(s.y, 0)),
        seats: flatSeats.map((p, i) => ({
          id: typeof rawSeats[i]?.id === 'string' ? rawSeats[i].id as string : `${srcId}-${p.row}-${p.number}`,
          row: p.row,
          number: p.number,
          kind: p.kind,
          x: Math.round(p.x),
          y: Math.round(p.y),
        })),
        rowPrices,
        shape: s.shape === 'arc' ? 'arc' : (s.shape === 'table' ? 'table' : 'grid'),
        arcSpread: num(s.arcSpread, 120),
        arcBaseR: num(s.arcBaseR, 200),
        arcRowGap: num(s.arcRowGap, 24),
        arcOffsetX: num(s.arcOffsetX, 0),
        arcOffsetY: num(s.arcOffsetY, 0),
        type: (s.type === 'standing' || s.type === 'mixed') ? s.type : 'seated',
      })
    }
  } else {
    // БД-формат: общий масштаб по всей схеме (координаты сидера могут быть
    // нормализованы в 0..59/0..39 или лежать в пикселях чужого холста).
    const pad = 40
    const viewW = Math.max(canvas.width, 900)
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
        if (rn > 0 && Number.isFinite(val) && val >= 0) rowPrices[rn] = Math.round(val)
      }
      const rows = Array.isArray(s.rows) ? s.rows as ServerSeat[] : []
      for (const r of rows) {
        putRowPrice(num(r?.number, 0), num(r?.price_amount, NaN))
      }
      // Легаси: цена могла лежать на уровне места (seat.price_amount / seat.price).
      for (const r of rows) {
        const rn = num(r?.number, 0)
        for (const seat of (r?.seats ?? []) as ServerSeat[]) {
          const sp = num(seat?.price_amount ?? seat?.price, NaN)
          if (Number.isFinite(sp) && !rowPrices[rn]) putRowPrice(rn, sp)
        }
      }
      // Если у сектора цена не задана вовсе, но ряды имеют цены — берём
      // минимальную цену ряда как базовую, иначе инспектор покажет 0 (B7).
      let secPrice = num(s.priceMinor ?? s.price, NaN)
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
      const seatPosCache = new Map<number, number>()
      let seatsSeenInRow = 0
      let lastRowSeen = Number.NaN
      flatSeats.forEach((p, i) => {
        if (p.row !== lastRowSeen) { seatsSeenInRow = 0; lastRowSeen = p.row }
        seatPosCache.set(i, seatsSeenInRow)
        seatsSeenInRow += 1
      })
      const seats: ESeat[] = flatSeats.map((p, i) => ({
        id: `${String(s.name)}-${p.row}-${p.number}-${i}`,
        row: p.row,
        number: p.number,
        kind: p.kind,
        x: degenerate
          ? (seatPosCache.get(i) ?? 0) * (SEAT + GAP)
          : Math.round((p.x - secMinX) * scale),
        y: degenerate
          ? (rowIndexOf.get(p.row) ?? 0) * (SEAT + ROW_GAP)
          : Math.round((p.y - secMinY) * scale),
      }))
      out.push({
        id: `s${out.length + 1}`,
        name: s.name as string,
        priceMinor: Math.max(0, Math.round(secPrice)),
        x: 0,
        y: 0,
        seats,
        rowPrices,
        shape: s.shape === 'arc' ? 'arc' : (s.shape === 'table' ? 'table' : 'grid'),
        arcSpread: num(s.arcSpread, 120),
        arcBaseR: num(s.arcBaseR, 200),
        arcRowGap: num(s.arcRowGap, 24),
        arcOffsetX: 0,
        arcOffsetY: 0,
        type: (s.type === 'standing' || s.type === 'mixed') ? s.type : 'seated',
      })
    }
    // Раскладка секторов БД-формата по вертикали, чтобы они не наложились.
    let cursorY = 140
    for (const sec of out) {
      sec.y = cursorY
      cursorY += bbox(sec).height + 80
    }
  }

  // Геометрия банкетных столов: кэшируем центр/радиус кольца для рендера.
  // Величина производная от мест, поэтому в payload не пишется и всегда
  // пересчитывается — round-trip детерминирован.
  for (const sec of out) {
    if (sec.shape === 'table') {
      const t = tableLayout(sec)
      sec.tableCx = t.cx
      sec.tableCy = t.cy
      sec.tableRing = t.ring
    }
  }

  const format: SchemaFormat = out.length > 0 ? (isEditorFormat ? 'editor' : 'db') : 'empty'
  return { format, canvas, sectors: out, statics, backgrounds, droppedSectors }
}

/**
 * Подпись стоячей зоны на холсте, которую редактор дорисовывает для схем
 * «БД-формата» (в них стоячая зона приходит сектором без геометрии — мест
 * у него нет, все в одной точке).
 *
 * Возвращает null, если дорисовывать нечего: для редакторского формата зона
 * всегда уже есть в staticObjects, и любая «дорисовка» ломала бы
 * идемпотентность импорта (файл после import → export переставал совпадать
 * с исходным и сервер создавал ЛИШНЮЮ стоячую зону в инвентаре).
 */
export function danceZoneFor(
  format: SchemaFormat,
  sectors: ESector[],
  statics: EStatic[],
  canvasSize: SchemaCanvas,
): EStatic | null {
  if (format !== 'db') return null
  const dance = sectors.find((s) => {
    const name = (s.name ?? '').toLocaleLowerCase('ru')
    const isTable = s.shape === 'table' || name.includes('стол')
    return !isTable && (/танцпол|dance/i.test(name) || (
      s.seats.length > 1
      && s.seats.every((p) => p.x === s.seats[0].x && p.y === s.seats[0].y)
    ))
  })
  if (!dance || dance.seats.length === 0) return null
  if (statics.some((o) => /танцпол|dance/i.test(o.text ?? ''))) return null
  const cx = canvasSize.width / 2
  const cy = 190
  const R = 60 + Math.min(dance.seats.length, 10) * 4
  return {
    id: 'static-dancezone',
    kind: 'standing',
    x: cx - R,
    y: cy - R * 0.6,
    width: R * 2,
    height: R * 1.2,
    text: `Танцпол · ${dance.seats.length} мест`,
    capacity: dance.seats.length,
  }
}

/* ── Структурная валидация импортируемого файла (§54) ───────────────── */

/**
 * Проверить импортируемую схему ДО применения. Возвращает список понятных
 * ошибок по полям или null, если схема пригодна к загрузке. Никаких мутаций —
 * плохой файл не затирает рабочую схему.
 */
export function validateSchema(data: unknown): string[] | null {
  const errors: string[] = []
  const root = unwrapSchemaRoot(data)
  if (!root) {
    return ['Файл должен быть объектом схемы зала (JSON-объект), а не массивом или пустым значением']
  }
  if (!('sectors' in root) && !('staticObjects' in root) && !('background' in root) && !('backgrounds' in root)) {
    return ['В файле нет ни `sectors`, ни `staticObjects`, ни `background` — это не похоже на схему зала']
  }
  const sectors = root.sectors
  if (sectors !== undefined && !Array.isArray(sectors)) {
    return ['Поле `sectors` должно быть массивом секторов']
  }
  const list = Array.isArray(sectors) ? sectors : []
  if (list.length === 0 && !Array.isArray(root.staticObjects) && !root.background && !root.backgrounds) {
    return ['В схеме нет ни одного сектора и ни одного объекта — импортировать нечего']
  }
  list.forEach((raw, i) => {
    if (typeof raw !== 'object' || raw === null) {
      errors.push(`sectors[${i}]: сектор должен быть объектом`)
      return
    }
    const s = raw as Record<string, unknown>
    if (typeof s.name !== 'string' || s.name.trim() === '') {
      errors.push(`sectors[${i}]: у сектора должно быть непустое имя (поле name)`)
    }
    if (s.seats !== undefined && !Array.isArray(s.seats)) {
      errors.push(`sectors[${i}].seats: места должны быть массивом`)
    }
    if (s.rows !== undefined && !Array.isArray(s.rows)) {
      errors.push(`sectors[${i}].rows: ряды должны быть массивом`)
    }
    const shape = s.shape
    if (shape !== undefined && !['grid', 'arc', 'table'].includes(shape as string)) {
      errors.push(`sectors[${i}].shape: недопустимая форма «${String(shape)}» (ожидается grid/arc/table)`)
    }
    if (shape === 'arc' && s.arcSpread !== undefined
      && (!Number.isInteger(s.arcSpread) || Number(s.arcSpread) < 10 || Number(s.arcSpread) > 180)) {
      errors.push(`sectors[${i}].arcSpread: угол дуги должен быть целым числом от 10° до 180°`)
    }
    const hasSeats = Array.isArray(s.seats) && (s.seats as unknown[]).length > 0
    const hasRows = Array.isArray(s.rows) && (s.rows as unknown[]).length > 0
    if (!hasSeats && !hasRows) {
      const nm = typeof s.name === 'string' && s.name.trim() !== '' ? s.name : String(i)
      errors.push(`sectors[${i}] (${nm}): нужны места (seats) или ряды (rows)`)
    }
    const checkSeat = (seat: unknown, path: string): void => {
      if (typeof seat !== 'object' || seat === null) {
        errors.push(`${path}: место должно быть объектом`)
        return
      }
      const m = seat as Record<string, unknown>
      if (m.row !== undefined && (!Number.isInteger(m.row) || (m.row as number) < 1)) {
        errors.push(`${path}.row: ряд должен быть целым числом ≥ 1`)
      }
      if (m.number !== undefined && (!Number.isInteger(m.number) || (m.number as number) < 1)) {
        errors.push(`${path}.number: номер места должен быть целым числом ≥ 1`)
      }
    }
    if (Array.isArray(s.seats)) (s.seats as unknown[]).forEach((seat, j) => checkSeat(seat, `sectors[${i}].seats[${j}]`))
    if (Array.isArray(s.rows)) {
      (s.rows as unknown[]).forEach((row, r) => {
        if (typeof row !== 'object' || row === null) {
          errors.push(`sectors[${i}].rows[${r}]: ряд должен быть объектом`)
          return
        }
        const rr = row as Record<string, unknown>
        const no = rr.number
        if (no !== undefined && (!Number.isInteger(Number(no)) || Number(no) < 1)) {
          errors.push(`sectors[${i}].rows[${r}].number: номер ряда должен быть целым числом ≥ 1`)
        }
        if (rr.seats !== undefined && !Array.isArray(rr.seats)) {
          errors.push(`sectors[${i}].rows[${r}].seats: места должны быть массивом`)
        }
        if (Array.isArray(rr.seats)) (rr.seats as unknown[]).forEach((seat, j) => checkSeat(seat, `sectors[${i}].rows[${r}].seats[${j}]`))
      })
    }
  })
  return errors.length > 0 ? errors : null
}
