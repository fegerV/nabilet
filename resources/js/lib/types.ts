/** Общие типы интерфейсов. Совпадают с машинами состояний docs/STATE-MACHINES.md. */

export type SeatState = 'free' | 'selected' | 'held' | 'sold' | 'unavailable' | 'vip' | 'accessible'

export type OrderStatus =
  | 'pending'
  | 'awaiting_payment'
  | 'paid'
  | 'payment_failed'
  | 'cancelled'
  | 'expired'
  | 'partially_refunded'
  | 'refunded'

export type TicketStatus = 'issued' | 'used' | 'cancelled' | 'refunded' | 'expired'

export type EventStatus = 'draft' | 'published' | 'sold_out' | 'finished' | 'cancelled'

export type CheckinResult =
  | 'valid'
  | 'already_used'
  | 'cancelled'
  | 'refunded'
  | 'expired'
  | 'invalid_signature'
  | 'wrong_session'
  | 'wrong_event'
  | 'unknown_ticket'
  | 'conflict'

/** Тональность статуса — не украшение, а соглашение: одинаковые статусы выглядят одинаково. */
export type Tone = 'neutral' | 'brand' | 'accent' | 'sun' | 'mint' | 'rose' | 'sky'

export interface EventCard {
  id: string
  slug?: string
  title: string
  subtitle?: string
  short_description?: string
  category?: string | { name?: string }
  venue?: string | { name?: string; city?: string } | null
  city?: string
  posterFrom?: string
  posterTo?: string
  posterAccent?: string
  poster?: string
  cover?: string
  priceFromMinor?: number
  price_from_minor?: number
  status: EventStatus
  sessionsCount?: number
  sessions_count?: number
  /** Сеансы: то, что реально покупает пользователь. */
  sessions?: Array<{
    id: string
    startsAt?: string
    starts_at?: string
    hall?: string
    hall_name?: string
    availableSeats?: number
    available_seats?: number
  }>
}

export interface OrderRow {
  id: string
  number: string
  customer: string
  email: string
  eventTitle: string
  sessionAt: string
  seats: number
  totalMinor: number
  status: OrderStatus
  channel: 'site' | 'telegram' | 'embed' | 'admin'
  createdAt: string
}

export interface TicketCard {
  id: string
  code: string
  eventTitle: string
  venue: string
  sessionAt: string
  sector: string
  row: number
  seat: number
  priceMinor: number
  status: TicketStatus
  qrPayload: string
}
