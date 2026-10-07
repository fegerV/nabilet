/**
 * Юнит-тесты сериализации/разбора схем залов (импорт-экспорт, §54).
 *
 * Раньше сборка payload была продублирована литералом в двух местах
 * HallEditorPage.vue (`buildSchemaPayload` для автосохранения и `exportSchema`
 * для файла), а разбор жил в компоненте и был недоступен тестам. Поэтому
 * расхождение экспорта и автосохранения и потеря данных при round-trip
 * обнаруживались только руками в браузере.
 *
 * Здесь проверяем именно контракт: файл → состояние → файл.
 */
import { describe, expect, it } from 'vitest'
import type { EBackground, ESector, EStatic } from '../../../resources/js/pages/hall-editor/editorTypes'
import {
  SCHEMA_VERSION,
  danceZoneFor,
  normalizeBackground,
  parseSchemaPayload,
  serializeSchema,
  validateSchema,
} from '../../../resources/js/pages/hall-editor/schemaSerialization'
import { buildGridSeats, buildTableSeats, rebuildTableSeats, tableLayout } from '../../../resources/js/pages/hall-editor/seatGeometry'

let counter = 0
const nextId = (prefix: string) => `${prefix}-${(counter += 1)}`

const CANVAS = { width: 900, height: 520 }

function gridSector(over: Partial<ESector> = {}): ESector {
  const seats = buildGridSeats(3, 4, 1, nextId)
  return {
    id: 'sec-parter',
    name: 'Партер',
    priceMinor: 120000,
    x: 40,
    y: 120,
    seats,
    rowPrices: { 1: 150000, 2: 120000 },
    shape: 'grid',
    arcSpread: 120,
    arcBaseR: 200,
    arcRowGap: 28,
    arcOffsetX: 0,
    arcOffsetY: 0,
    type: 'seated',
    ...over,
  }
}

function tableSector(count: number, over: Partial<ESector> = {}): ESector {
  const built = buildTableSeats(count, nextId)
  const sector: ESector = {
    id: `sec-table-${count}`,
    name: `Стол ${count}`,
    priceMinor: 900000,
    x: 200,
    y: 180,
    seats: built.seats,
    rowPrices: {},
    shape: 'table',
    arcSpread: 120,
    arcBaseR: 200,
    arcRowGap: 28,
    arcOffsetX: 0,
    arcOffsetY: 0,
    type: 'seated',
    ...over,
  }
  rebuildTableSeats(sector, count, nextId)
  return sector
}

// Статика ровно в том виде, в каком её создаёт редактор (все поля заданы).
const STAGE: EStatic = { id: 'stage-1', kind: 'stage', x: 300, y: 20, width: 300, height: 60, rotation: 0, opacity: 1, locked: false, text: 'Сцена' }
const STANDING: EStatic = { id: 'zone-1', kind: 'standing', x: 300, y: 380, width: 300, height: 100, rotation: 0, opacity: 1, locked: false, text: 'Фан-зона · 150', capacity: 150, priceMinor: 50000 }

const BG_1: EBackground = { id: 'bg-1', src: 'data:image/png;base64,AAA', x: 0, y: 0, width: 900, height: 520, rotation: 0, locked: false, opacity: 1 }
const BG_2: EBackground = { id: 'bg-2', src: 'data:image/png;base64,BBB', x: 20, y: 30, width: 400, height: 300, rotation: 15, locked: true, opacity: 0.5 }

function state(sectors: ESector[], statics: EStatic[] = [STAGE], backgrounds: EBackground[] = []) {
  return { canvasSize: CANVAS, sectors, statics, backgrounds }
}

describe('serializeSchema', () => {
  it('пишет версию формата, холст и все сектора', () => {
    const payload = serializeSchema(state([gridSector()]))
    expect(payload.version).toBe(SCHEMA_VERSION)
    expect(payload.canvas).toEqual(CANVAS)
    expect(payload.sectors).toHaveLength(1)
    expect(payload.sectors[0].seats).toHaveLength(12)
    expect(payload.sectors[0].rowPrices).toEqual({ 1: 150000, 2: 120000 })
  })

  it('сохраняет ВСЕ подложки, а не только первую', () => {
    const payload = serializeSchema(state([gridSector()], [], [BG_1, BG_2]))
    // `background` остаётся для обратной совместимости…
    expect(payload.background).toEqual(BG_1)
    // …а полный список не теряется.
    expect(payload.backgrounds).toEqual([BG_1, BG_2])
  })

  it('не пишет производную геометрию стола (tableCx/tableCy/tableRing)', () => {
    const sector = tableSector(8)
    expect(sector.tableRing).toBeGreaterThan(0)
    const payload = serializeSchema(state([sector]))
    expect(payload.sectors[0]).not.toHaveProperty('tableCx')
    expect(payload.sectors[0]).not.toHaveProperty('tableCy')
    expect(payload.sectors[0]).not.toHaveProperty('tableRing')
  })

  it('не мутирует переданное состояние', () => {
    const sector = gridSector()
    const before = JSON.stringify(sector)
    serializeSchema(state([sector], [STAGE], [BG_1]))
    expect(JSON.stringify(sector)).toBe(before)
  })
})

describe('parseSchemaPayload: редакторский формат', () => {
  it('round-trip «файл → состояние → файл» без потерь', () => {
    const original = serializeSchema(state(
      [gridSector(), tableSector(6), gridSector({ id: 'sec-balcony', name: 'Балкон', x: 500, y: 40 })],
      [STAGE, STANDING],
      [BG_1, BG_2],
    ))
    const parsed = parseSchemaPayload(JSON.parse(JSON.stringify(original)), CANVAS)
    const again = serializeSchema({
      canvasSize: parsed.canvas,
      sectors: parsed.sectors,
      statics: parsed.statics,
      backgrounds: parsed.backgrounds,
    })
    expect(again).toEqual(original)
  })

  it('нормализует минимальную статику один раз, дальше round-trip стабилен', () => {
    // Импорт из чужого файла дописывает дефолты (rotation/opacity/locked) —
    // это нормализация, а не потеря. Важно, что ВТОРОЙ проход ничего не меняет,
    // иначе каждый import→export «раздувал» бы файл.
    const minimal = { sectors: [serializeSchema(state([gridSector()])).sectors[0]], staticObjects: [{ id: 's', kind: 'stage', x: 1, y: 2 }] }
    const once = parseSchemaPayload(JSON.parse(JSON.stringify(minimal)), CANVAS)
    const first = serializeSchema({ canvasSize: once.canvas, sectors: once.sectors, statics: once.statics, backgrounds: once.backgrounds })
    const twice = parseSchemaPayload(JSON.parse(JSON.stringify(first)), CANVAS)
    const second = serializeSchema({ canvasSize: twice.canvas, sectors: twice.sectors, statics: twice.statics, backgrounds: twice.backgrounds })
    expect(second).toEqual(first)
  })

  it('сохраняет локальные координаты мест и смещение сектора 1:1', () => {
    const sector = gridSector({ x: 137, y: 84 })
    const parsed = parseSchemaPayload(serializeSchema(state([sector])), CANVAS)
    expect(parsed.format).toBe('editor')
    expect(parsed.sectors[0].x).toBe(137)
    expect(parsed.sectors[0].y).toBe(84)
    expect(parsed.sectors[0].seats.map((s) => [s.x, s.y])).toEqual(sector.seats.map((s) => [s.x, s.y]))
  })

  it('восстанавливает геометрию стола детерминированно', () => {
    const sector = tableSector(10)
    const expected = tableLayout(sector)
    const parsed = parseSchemaPayload(serializeSchema(state([sector])), CANVAS)
    expect(parsed.sectors[0].tableCx).toBeCloseTo(expected.cx, 6)
    expect(parsed.sectors[0].tableCy).toBeCloseTo(expected.cy, 6)
    expect(parsed.sectors[0].tableRing).toBeCloseTo(expected.ring, 6)
  })

  it('принимает payload, пришедший JSON-строкой (драйвер БД отдаёт JSON как текст)', () => {
    const payload = serializeSchema(state([gridSector()]))
    const parsed = parseSchemaPayload(JSON.stringify(payload), CANVAS)
    expect(parsed.sectors).toHaveLength(1)
    expect(parsed.sectors[0].seats).toHaveLength(12)
  })

  it('читает легаси-подложку из одиночного `background`', () => {
    const legacy = { version: '1.0', canvas: CANVAS, background: BG_1, sectors: [gridSector()], staticObjects: [] }
    const parsed = parseSchemaPayload(legacy, CANVAS)
    expect(parsed.backgrounds).toEqual([BG_1])
  })

  it('переносит типы мест vip/accessible без потерь', () => {
    const sector = gridSector()
    sector.seats[0].kind = 'vip'
    sector.seats[1].kind = 'accessible'
    const parsed = parseSchemaPayload(serializeSchema(state([sector])), CANVAS)
    expect(parsed.sectors[0].seats[0].kind).toBe('vip')
    expect(parsed.sectors[0].seats[1].kind).toBe('accessible')
  })
})

describe('parseSchemaPayload: БД-формат (sectors[].rows[].seats[])', () => {
  const dbPayload = {
    version: '1.0',
    canvas: { width: 900, height: 520 },
    sectors: [
      {
        name: 'Партер', code: 'PARTER', type: 'seated', x: 0, y: 0,
        rows: [
          { number: '1', price_amount: 150000, seats: [{ number: '1', type: 'standard', x: 5, y: 6 }, { number: '2', type: 'vip', x: 7, y: 6 }] },
          { number: '2', price_amount: 90000, seats: [{ number: '1', type: 'standard', x: 5, y: 9 }, { number: '2', type: 'wheelchair', x: 7, y: 9 }] },
        ],
      },
    ],
  }

  it('распознаётся как db-формат и разворачивается в места', () => {
    const parsed = parseSchemaPayload(dbPayload, CANVAS)
    expect(parsed.format).toBe('db')
    expect(parsed.sectors[0].seats).toHaveLength(4)
    expect(parsed.sectors[0].rowPrices).toEqual({ 1: 150000, 2: 90000 })
  })

  it('маппит типы мест БД (wheelchair → accessible, vip → vip)', () => {
    const parsed = parseSchemaPayload(dbPayload, CANVAS)
    const kinds = parsed.sectors[0].seats.map((s) => s.kind)
    expect(kinds).toContain('vip')
    expect(kinds).toContain('accessible')
  })

  it('раскладывает места по сетке редактора, если координаты вырождены (все нули)', () => {
    const flat = {
      sectors: [{
        name: 'Зал', type: 'seated',
        rows: [
          { number: '1', seats: [{ number: '1', x: 0, y: 0 }, { number: '2', x: 0, y: 0 }] },
          { number: '2', seats: [{ number: '1', x: 0, y: 0 }, { number: '2', x: 0, y: 0 }] },
        ],
      }],
    }
    const parsed = parseSchemaPayload(flat, CANVAS)
    const seats = parsed.sectors[0].seats
    expect(new Set(seats.map((s) => `${s.x},${s.y}`)).size).toBe(4)
  })

  it('разносит сектора БД-формата по вертикали (не накладываются)', () => {
    const two = {
      sectors: [
        { name: 'A', type: 'seated', rows: [{ number: '1', seats: [{ number: '1', x: 5, y: 6 }] }] },
        { name: 'B', type: 'seated', rows: [{ number: '1', seats: [{ number: '1', x: 5, y: 6 }] }] },
      ],
    }
    const parsed = parseSchemaPayload(two, CANVAS)
    expect(parsed.sectors[0].y).not.toBe(parsed.sectors[1].y)
  })

  it('возвращает пустой формат для схемы без секторов', () => {
    const parsed = parseSchemaPayload({ sectors: [], staticObjects: [STAGE] }, CANVAS)
    expect(parsed.format).toBe('empty')
    expect(parsed.sectors).toEqual([])
    expect(parsed.statics).toHaveLength(1)
  })

  it('сообщает о выброшенных секторах без мест', () => {
    const parsed = parseSchemaPayload({ sectors: [{ name: 'Рамка' }, { name: 'Зал', seats: [{ row: 1, number: 1, x: 1, y: 1 }] }] }, CANVAS)
    expect(parsed.droppedSectors).toEqual(['Рамка'])
  })
})

describe('danceZoneFor: идемпотентность импорта', () => {
  const danceSector = (): ESector => gridSector({
    id: 'sec-dance',
    name: 'Танцпол',
    type: 'standing',
    seats: Array.from({ length: 5 }, (_, i) => ({ id: `d${i}`, row: 1, number: i + 1, kind: 'standard' as const, x: 0, y: 0 })),
  })

  it('дорисовывает подпись только для БД-формата', () => {
    const zone = danceZoneFor('db', [danceSector()], [], CANVAS)
    expect(zone).not.toBeNull()
    expect(zone?.capacity).toBe(5)
  })

  it('для редакторского формата ничего не добавляет — иначе import→export не идемпотентен', () => {
    expect(danceZoneFor('editor', [danceSector()], [], CANVAS)).toBeNull()
  })

  it('не дублирует зону, если она уже есть в статике', () => {
    const existing: EStatic = { id: 'z', kind: 'standing', x: 0, y: 0, text: 'Танцпол · 5 мест', capacity: 5 }
    expect(danceZoneFor('db', [danceSector()], [existing], CANVAS)).toBeNull()
  })

  it('не принимает банкетные столы на танцполе за стоячую зону', () => {
    const table = tableSector(10, { name: 'Столы на танцполе · стол 57' })
    expect(danceZoneFor('db', [table], [], CANVAS)).toBeNull()
  })
})

describe('normalizeBackground', () => {
  it('подставляет размер холста, если размер не задан', () => {
    const bg = normalizeBackground({ src: 'x.png' }, CANVAS)
    expect(bg?.width).toBe(900)
    expect(bg?.height).toBe(520)
    expect(bg?.opacity).toBe(1)
  })

  it('зажимает прозрачность в 0..1 и отбрасывает запись без src', () => {
    expect(normalizeBackground({ src: 'x.png', opacity: 7 }, CANVAS)?.opacity).toBe(1)
    expect(normalizeBackground({ src: 'x.png', opacity: -3 }, CANVAS)?.opacity).toBe(0)
    expect(normalizeBackground({ src: '' }, CANVAS)).toBeNull()
  })
})

describe('validateSchema: отказ на битых файлах', () => {
  it('отвергает не-объект', () => {
    expect(validateSchema([])).not.toBeNull()
    expect(validateSchema(null)).not.toBeNull()
    expect(validateSchema('строка')).not.toBeNull()
  })

  it('отвергает сектор без имени, без мест и с недопустимой формой', () => {
    expect(validateSchema({ sectors: [{ seats: [{ row: 1, number: 1 }] }] })).not.toBeNull()
    expect(validateSchema({ sectors: [{ name: 'Пусто' }] })).not.toBeNull()
    expect(validateSchema({ sectors: [{ name: 'A', shape: 'triangle', seats: [{ row: 1, number: 1 }] }] })).not.toBeNull()
  })

  it('отвергает угол дуги вне 10..180 и номер ряда < 1', () => {
    expect(validateSchema({ sectors: [{ name: 'A', shape: 'arc', arcSpread: 400, seats: [{ row: 1, number: 1 }] }] })).not.toBeNull()
    expect(validateSchema({ sectors: [{ name: 'A', seats: [{ row: 0, number: 1 }] }] })).not.toBeNull()
  })

  it('принимает корректный экспорт редактора', () => {
    const payload = serializeSchema(state([gridSector(), tableSector(8)], [STAGE, STANDING], [BG_1, BG_2]))
    expect(validateSchema(payload)).toBeNull()
  })

  it('принимает файл БД-формата и разворачивает { schema: ... }', () => {
    const db = { sectors: [{ name: 'A', rows: [{ number: '1', seats: [{ number: 1, x: 1, y: 1 }] }] }] }
    expect(validateSchema(db)).toBeNull()
    expect(validateSchema({ schema: db })).toBeNull()
  })

  it('принимает файл только со статикой (пустой зал с декорациями)', () => {
    expect(validateSchema({ sectors: [], staticObjects: [STAGE] })).toBeNull()
  })
})
