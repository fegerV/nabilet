/**
 * API-клиент «Мои билеты» (витрина).
 *
 * Гостевая витрина не имеет аккаунта, поэтому билеты отдаются не по auth:api,
 * а по гостевому токену корзины (`X-Cart-Token`, контракт D5): endpoint
 * `GET /api/v1/my-tickets` резолвит «мои» заказы через корзины этого браузера.
 * Без токена (чистый вход) сервер возвращает пустой список — гость видит
 * пустой экран, а не ошибку авторизации.
 */
import { get, send } from './api'
import type { TicketCard, TicketStatus, TicketTemplateConfig } from './types'

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
  holder?: string | null
  orderId?: number | string | null
  template?: TicketTemplateConfig | null
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
    holder: raw.holder ?? '',
    orderId: raw.orderId ?? '',
    template: raw.template ?? null,
  }
}

/** Получить билеты текущего покупателя (идентификация по X-Cart-Token). */
export async function fetchMyTickets(): Promise<TicketCard[]> {
  const res = await get<RawTicket[]>('/my-tickets')
  const list = Array.isArray(res?.data) ? res.data : []
  return list.map(normalize)
}

/**
 * Повторно отправить письмо с билетом — при необходимости на исправленный адрес.
 *
 * Существует потому, что письмо уходит на адрес, введённый при оформлении, и
 * опечатка в нём означала, что билет не придёт НИКОГДА: повторной отправки не
 * было, а исправить адрес было нечем. Доступ к заказу подтверждается гостевым
 * токеном корзины (заголовок `X-Cart-Token`, подставляет `api.ts`) — ровно как
 * у `GET /my-tickets`. Чужой заказ сервер отдаёт как 404.
 *
 * Возвращает адрес, на который письмо ушло, — его показываем покупателю, чтобы
 * он видел, куда именно отправлено.
 */
export async function resendTicket(orderId: number | string, email?: string): Promise<string> {
  const trimmed = (email ?? '').trim()
  const res = await send<{ sent: boolean; email: string }>('/my-tickets/resend', 'POST', {
    // Заказ адресуется публичным `public_id` из `orderId`, а не внутренним id.
    // Витрина всегда передаёт строку (`String(t.orderId)` в TicketsPage), поэтому
    // отправляем как есть — иначе `Number(public_id)` дал бы NaN.
    order: String(orderId),
    // Пустая строка = «адрес не меняем»: сервер сам решит, что слать некуда
    // не нужно и отправит на сохранённый.
    ...(trimmed !== '' ? { email: trimmed } : {}),
  })
  return res.data.email
}
