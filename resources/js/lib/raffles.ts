/**
 * Розыгрыши «счастливый билет» (фаза 5) — клиент API.
 *
 * ДВЕ ЗОНЫ ДОСТУПА, ОДИН ФАЙЛ
 *   Кабинет покупателя (`/me/raffles`) и публичная страница результатов
 *   (`/raffles/{public_id}`) читают без токена; управление организатором
 *   (`/admin/raffles/*`) — с админским токеном, который `get`/`send`
 *   подставляют сами. Поэтому функции лежат рядом: контракт один, различается
 *   только путь.
 *
 * ДАТЫ ПРИХОДЯТ ISO-8601
 *   Сервер отдаёт `starts_at`/`ends_at`/`drawn_at` строками UTC. Перевод в
 *   локальное время делает браузер при показе (`toLocaleString`), а обратно в
 *   форму уходит `datetime-local` — тем же помощником `toLocalInput`, что
 *   держит форму и поле в одном формате.
 */

import { get, send } from '@/lib/api'
import type { RaffleStatus } from '@/lib/types'

/**
 * Статусы розыгрыша — значения `RaffleStateMachine` и `ck_raffles_status`.
 *
 * ПОДПИСЕЙ И ЦВЕТОВ ЗДЕСЬ БОЛЬШЕ НЕТ. Тут жили `RAFFLE_STATUS_LABELS` и
 * `raffleStatusClass()`, причём второй — «тесной копией палитры админки»:
 * он выписывал Tailwind-классы руками и подмешивался в `NBadge` через
 * `:class`, поверх собственных классов бейджа. Копия палитры успела разойтись:
 * `drawn` был покрашен в `indigo` — цвет ДЕФОЛТНОЙ палитры Tailwind, которого
 * нет среди токенов проекта. Он рисуется (палитра расширена, а не заменена),
 * но «Разыгран» — единственное ПОЛОЖИТЕЛЬНОЕ терминальное состояние — выпадал
 * из семантики и оставался единственным синим элементом интерфейса. Заодно все
 * плашки розыгрышей потеряли `ring`, который настоящие тона `NBadge` дают:
 * копия воспроизвела фон и текст, но не всё правило.
 *
 * Файл остаётся клиентом API: значения статусов — часть контракта (они
 * приходят в ответе), а подписи и цвета — часть интерфейса, и живут они в
 * `lib/statusLabels.ts` вместе с остальными словарями.
 */
export const RAFFLE_STATUSES: readonly RaffleStatus[] = ['draft', 'active', 'drawn', 'cancelled']

export type { RaffleStatus }

export interface RafflePrize {
  public_id: string
  place: number
  title: string
  description: string | null
}

export interface RaffleWinner {
  public_id: string
  place: number
  prize_title: string
  order_number: string
  customer_email: string
  notified: boolean
}

/** Строка списка в админке — со счётчиками. */
export interface RaffleListItem {
  public_id: string
  title: string
  status: RaffleStatus
  starts_at: string | null
  ends_at: string | null
  drawn_at: string | null
  prizes_count: number
  entries_count: number
  winners_count: number
  created_at: string
}

export interface RaffleDetail {
  public_id: string
  title: string
  description: string | null
  status: RaffleStatus
  starts_at: string | null
  ends_at: string | null
  drawn_at: string | null
  created_at: string
  updated_at: string
  entries_count: number
  prizes: RafflePrize[]
  winners: RaffleWinner[]
}

/** Моё участие: один элемент на розыгрыш. */
export interface RaffleParticipation {
  public_id: string
  title: string
  description: string | null
  status: RaffleStatus
  starts_at: string | null
  ends_at: string | null
  drawn_at: string | null
  entries_count: number
  prizes: Array<{ place: number; title: string }>
  won: Array<{ place: number; prize_title: string; notified: boolean }>
}

/** Победитель на публичной странице — всегда маска. */
export interface PublicWinner {
  display: string
  notified: boolean
}

export interface PublicPrize {
  place: number
  title: string
  description: string | null
  winner: PublicWinner | null
}

export interface RaffleResults {
  public_id: string
  title: string
  description: string | null
  status: RaffleStatus
  starts_at: string | null
  ends_at: string | null
  drawn_at: string | null
  entries_count: number
  prizes: PublicPrize[]
}

/** Пара «дата → значение input[type=datetime-local]». */
export function toLocalInput(iso: string | null): string {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** Обратно: значение формы → ISO-строка (пустая строка → null). */
export function fromLocalInput(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date.toISOString()
}

/** Приз в форме: место, название, описание. */
export interface RafflePrizeDraft {
  place: number
  title: string
  description: string
}

/** Тело создания/правки розыгрыша. */
interface RafflePayload {
  title: string
  description: string
  starts_at: string | null
  ends_at: string | null
  prizes?: RafflePrizeDraft[]
}

async function unwrap<T>(promise: Promise<{ data: unknown }>): Promise<T> {
  const res = await promise
  const payload = res.data as { data?: T } | T
  return ((payload as { data?: T }).data ?? payload) as T
}

/** GET /admin/raffles — список со счётчиками. */
export async function fetchAdminRaffles(): Promise<RaffleListItem[]> {
  return unwrap<RaffleListItem[]>(get<{ data: RaffleListItem[] }>('/admin/raffles'))
}

/** GET /admin/raffles/{id} — розыгрыш с призами и победителями. */
export async function fetchAdminRaffle(publicId: string): Promise<RaffleDetail> {
  return unwrap<RaffleDetail>(get<{ data: RaffleDetail }>(`/admin/raffles/${publicId}`))
}

/** POST /admin/raffles — создать черновик. */
export async function createAdminRaffle(payload: RafflePayload): Promise<RaffleDetail> {
  return unwrap<RaffleDetail>(send<{ data: RaffleDetail }>('/admin/raffles', 'POST', payload))
}

/** PUT /admin/raffles/{id} — правка полей и призов. */
export async function updateAdminRaffle(publicId: string, payload: Partial<RafflePayload>): Promise<RaffleDetail> {
  return unwrap<RaffleDetail>(send<{ data: RaffleDetail }>(`/admin/raffles/${publicId}`, 'PUT', payload))
}

/** POST /admin/raffles/{id}/activate — начать набор участников (+ backfill). */
export async function activateAdminRaffle(publicId: string): Promise<RaffleDetail> {
  return unwrap<RaffleDetail>(send<{ data: RaffleDetail }>(`/admin/raffles/${publicId}/activate`, 'POST', {}))
}

/** POST /admin/raffles/{id}/cancel — закрыть без тиража. */
export async function cancelAdminRaffle(publicId: string): Promise<RaffleDetail> {
  return unwrap<RaffleDetail>(send<{ data: RaffleDetail }>(`/admin/raffles/${publicId}/cancel`, 'POST', {}))
}

/** POST /admin/raffles/{id}/draw — провести тираж. */
export async function drawAdminRaffle(publicId: string): Promise<RaffleWinner[]> {
  return unwrap<RaffleWinner[]>(send<{ data: RaffleWinner[] }>(`/admin/raffles/${publicId}/draw`, 'POST', {}))
}

/** POST /admin/raffles/{id}/notify — разослать письма победителям. */
export async function notifyAdminRaffle(publicId: string): Promise<{ raffle: string; pending: number }> {
  return unwrap<{ raffle: string; pending: number }>(
    send<{ data: { raffle: string; pending: number } }>(`/admin/raffles/${publicId}/notify`, 'POST', {}),
  )
}

/** GET /me/raffles — мои участия (кабинет покупателя). */
export async function fetchMyRaffles(): Promise<RaffleParticipation[]> {
  return unwrap<RaffleParticipation[]>(get<{ data: RaffleParticipation[] }>('/me/raffles'))
}

/** GET /raffles/{public_id} — публичная страница результатов. */
export async function fetchRaffleResults(publicId: string): Promise<RaffleResults> {
  return unwrap<RaffleResults>(get<{ data: RaffleResults }>(`/raffles/${publicId}`))
}
