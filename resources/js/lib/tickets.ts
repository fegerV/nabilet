/**
 * API-клиент «Мои билеты» (витрина).
 *
 * Гостевая витрина не имеет аккаунта, поэтому билеты отдаются не по auth:api,
 * а по гостевому токену корзины (`X-Cart-Token`, контракт D5): endpoint
 * `GET /api/v1/my-tickets` резолвит «мои» заказы через корзины этого браузера.
 * Без токена (чистый вход) сервер возвращает пустой список — гость видит
 * пустой экран, а не ошибку авторизации.
 */
import { get } from './api'
import type { TicketCard, TicketStatus } from './types'

/** Форма ответа GET /my-tickets (совпадает с TicketCardResource на бэкенде). */
interface RawTicket {
  id: string
  code: string
  eventTitle: string
  venue: string
  sessionAt: string | null
  sector: string
  row: number
  seat: number
  priceMinor: number
  status: string
  qrPayload: string | null
}

const KNOWN: TicketStatus[] = ['issued', 'used', 'cancelled', 'refunded', 'expired', 'revoked']

/** Привести ответ сервера к типу TicketCard с безопасным статусом. */
function normalize(raw: RawTicket): TicketCard {
  // Неизвестный статус закрываем как отозванный: никогда не превращать
  // неизвестный/новый серверный статус в активный билет по умолчанию.
  const status = (KNOWN.includes(raw.status as TicketStatus) ? raw.status : 'revoked') as TicketStatus
  return {
    id: raw.id,
    code: raw.code,
    eventTitle: raw.eventTitle,
    venue: raw.venue,
    sessionAt: raw.sessionAt ?? '',
    sector: raw.sector,
    row: Number(raw.row) || 0,
    seat: Number(raw.seat) || 0,
    priceMinor: Number(raw.priceMinor) || 0,
    status,
    qrPayload: raw.qrPayload ?? '',
  }
}

/** Получить билеты текущего покупателя (идентификация по X-Cart-Token). */
export async function fetchMyTickets(): Promise<TicketCard[]> {
  const res = await get<RawTicket[]>('/my-tickets')
  const list = Array.isArray(res?.data) ? res.data : []
  return list.map(normalize)
}
