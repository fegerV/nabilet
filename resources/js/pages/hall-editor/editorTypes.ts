/**
 * Типы редактора схем залов.
 *
 * Извлечены из HallEditorPage.vue (рефакторинг «god-компонента»): страница,
 * инструменты и панели свойств должны опираться на один контракт данных,
 * а не дублировать определения.
 */

export type SeatKind = 'standard' | 'vip' | 'accessible'

export type Tool =
  | 'select' | 'pan' | 'zoom' | 'seat' | 'row'
  | 'sector' | 'table' | 'standing' | 'text'
  | 'image' | 'stage' | 'entrance'

export type Autosave = 'saving' | 'saved' | 'error' | 'offline'

export type StaticKind = 'stage' | 'entrance' | 'label' | 'table' | 'standing' | 'text'

export interface ESeat {
  id: string
  row: number
  number: number
  kind: SeatKind
  x: number
  y: number
  /** Индивидуальная цена места (минорные единицы). null — брать цену ряда/сектора. */
  priceMinor?: number | null
}

export type SectorShape = 'grid' | 'arc' | 'table'

export interface ESector {
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
  /**
   * Тип сектора из БД-формата (ck_sectors_type: seated|standing|mixed).
   * Нужен для round-trip: без него серверная конвертация в инвентарь
   * теряла standing-секторы (C1), а F5 — их тип (B7).
   */
  type?: 'seated' | 'standing' | 'mixed'
  /**
   * Геометрия банкетного стола (shape === 'table'): центр кольца мест и его
   * радиус. Вычисляется из мест (tableLayout), здесь — кэш для рендера/импорта.
   */
  tableCx?: number
  tableCy?: number
  tableRing?: number
}

export interface EStatic {
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
  /** Цена standing-зоны в минорных единицах (копейках). */
  priceMinor?: number
}

export interface EBackground {
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

/** Снимок схемы для истории undo/redo. */
export interface SchemaSnapshot {
  sectors: ESector[]
  statics: EStatic[]
  backgrounds: EBackground[]
}
