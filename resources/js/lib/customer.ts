/**
 * Личный кабинет покупателя: сессия витрины и вызовы `/api/v1/me/*`.
 *
 * ЧЕМ ЭТО ОТЛИЧАЕТСЯ ОТ `auth.ts`
 *   `auth.ts` — про админку: `login()` кладёт токен в ключ СОТРУДНИКА, а
 *   `isAdminSession()` решает по ролям, пускать ли в панель. Здесь — вторая
 *   аудитория: покупатель. Токен у неё свой (`CUSTOMER_TOKEN_KEY`, см. `api.ts`),
 *   потому что иначе заход покупателя на `/admin` (ссылка «Организаторам» есть в
 *   меню витрины) вызывал бы `clearSession()` в guard'е админки и разлогинивал
 *   его прямо посреди покупки.
 *
 * ЧТО ЗДЕСЬ ЕСТЬ И ЗАЧЕМ
 *   Аккаунт у покупателя появляется автоматически при оплате заказа
 *   (`CustomerAccountService::resolveForCheckout()` на сервере), с `password =
 *   NULL` и без подтверждённого адреса. Поэтому «войти» для такого покупателя
 *   означает не «ввести пароль» (его нет), а «задать пароль по ссылке из
 *   письма»: `POST /auth/password/forgot` → письмо со ссылкой
 *   `/#/reset-password?token=…&email=…` → `POST /auth/password/reset`. Оба
 *   вызова здесь, чтобы у витрины был один вход в этот сценарий.
 */
import { request, get, send, setCustomerToken, getCustomerToken, type ApiError } from './api'

/**
 * Пользователь в форме схемы `User` контракта (`AuthResource`).
 *
 * `id` — `public_id` (ULID base32), СТРОКА. Числового внутреннего id наружу не
 * отдаёт ни один маршрут (`DATABASE.md` §9), и адресовать пользователя в API
 * можно только этим значением.
 */
export interface CustomerUser {
  id: string
  email: string
  email_verified_at?: string | null
  first_name?: string | null
  last_name?: string | null
  phone?: string | null
  status?: string
  locale?: string | null
  timezone?: string | null
  created_at?: string | null
  last_login_at?: string | null
  roles?: string[]
}

/** Одна строка `GET /me/sessions` (см. `UserSessionResource`). */
export interface CustomerSession {
  id: string
  device_name?: string | null
  user_agent?: string | null
  last_seen_at?: string | null
  created_at?: string | null
}

/**
 * Заказ покупателя в виде, удобном витрине.
 *
 * ФОРМА ОТВЕТА ОДНА
 *   `GET /me/orders` отдаёт схему `Order` контракта (`OrderResource`) — ту же,
 *   что и админский `GET /orders`. Раньше здесь стоял нормализатор на две формы:
 *   контроллер сериализовал модель заказа напрямую, поэтому наружу уходили
 *   КОЛОНКИ таблицы (`public_id`, `total_amount`, `customer_email`), тогда как
 *   схема и ресурс описывали другие две. Расхождение сведено (§3.28): суммы
 *   теперь в `totals`, покупатель — плоскими `customer_email` / `customer_name` /
 *   `customer_phone`, позиции несут `event_title`.
 */
export interface CustomerOrder {
  id: string
  number: string
  status: string
  paymentStatus: string | null
  totalMinor: number
  currency: string
  createdAt: string
  itemsCount: number
  /** Названия мероприятий из позиций — по ним покупатель узнаёт заказ. */
  eventTitles: string[]
  email: string
}

const USER_KEY = 'nabilet_customer_user'

/** Сохранённый покупатель (без запроса) — для шапки витрины. */
export function customerUser(): CustomerUser | null {
  try {
    const raw = localStorage.getItem(USER_KEY)
    return raw ? (JSON.parse(raw) as CustomerUser) : null
  } catch {
    return null
  }
}

export function saveCustomerUser(user: CustomerUser): void {
  try {
    localStorage.setItem(USER_KEY, JSON.stringify(user))
  } catch {
    /* ignore */
  }
}

export function clearCustomerUser(): void {
  try {
    localStorage.removeItem(USER_KEY)
  } catch {
    /* ignore */
  }
}

/** Есть ли сессия покупателя. Именно по токену, а не по сохранённому профилю. */
export function isCustomerSession(): boolean {
  return getCustomerToken() !== null
}

/* ── Проверка, что токен ещё живой ──────────────────────────────────────────
 * `isCustomerSession()` отвечает на вопрос «токен лежит в localStorage?», а не
 * «сессия работает?». Это разные вещи: токен истекает (30 дней,
 * `SessionIssuer::DEFAULT_TTL_DAYS`), отзывается выходом на другом устройстве
 * и сменой пароля (она отзывает ВСЕ сессии), а на стенде исчезает вместе с
 * пересозданной базой. Пока проверки не было, такой токен проходил guard
 * кабинета, `/me/*` отвечали 401, и покупатель оставался на странице с нулями
 * вместо формы входа.
 *
 * ПОЧЕМУ КЭШ НА ТОКЕН, А НЕ НА ВРЕМЯ
 *   Проверка стоит одного `GET /me`, и делать её на каждый переход нельзя:
 *   навигация ждала бы сеть. Но и «раз в N минут» — лишнее состояние: токен
 *   либо тот же самый (значит, уже проверен в этом документе), либо новый
 *   (после входа) — и тогда проверить его нужно ровно один раз.
 */
let validatedToken: string | null = null
/** Проверка в полёте: два guard'а подряд не должны слать два запроса. */
let probe: Promise<boolean> | null = null

/**
 * `true`, если сессия покупателя рабочая. `false` — токена нет или сервер его
 * не признал (тогда сессия уже очищена).
 *
 * Сетевую ошибку и 5xx считаем «сессия, скорее всего, жива»: офлайн или
 * перегруженный сервер — не повод выбрасывать покупателя на форму входа.
 */
export async function ensureCustomerSession(): Promise<boolean> {
  const token = getCustomerToken()
  if (token === null) return false
  if (validatedToken === token) return true
  if (probe !== null) return probe

  probe = (async () => {
    try {
      // `as: 'customer'` обязателен: без него `activeToken()` предпочтёт токен
      // сотрудника, и в браузере с открытой админкой проверялся бы он, а
      // профиль покупателя затёрся бы профилем администратора.
      const res = await request<{ data: CustomerUser }>('/me', { as: 'customer' })
      saveCustomerUser(res.data)
      validatedToken = token
      return true
    } catch (e) {
      const status = (e as ApiError | null)?.status ?? 0
      if (status === 401 || status === 403) {
        // Токен мёртв. `request()` уже стёр его из localStorage; чистим и
        // профиль — иначе шапка продолжит показывать имя того, кем покупатель
        // уже не является.
        clearCustomerSession()
        validatedToken = null
        return false
      }
      return true
    } finally {
      probe = null
    }
  })()

  return probe
}

/** Полностью очистить сессию витрины: и токен, и сохранённый профиль. */
export function clearCustomerSession(): void {
  setCustomerToken(null)
  clearCustomerUser()
  validatedToken = null
}

interface AuthPayload {
  user: CustomerUser
  token: string
  token_type: string
}

export interface RegisterPayload {
  email: string
  password: string
  password_confirmation: string
  first_name: string
  last_name: string
  phone?: string
}

/**
 * Регистрация покупателя.
 *
 * Сервер отвечает 201 и сразу выдаёт сессию (`POST /auth/register`), поэтому
 * после регистрации покупатель уже внутри — отдельный вход не нужен.
 */
export async function registerCustomer(payload: RegisterPayload): Promise<CustomerUser> {
  const res = await send<AuthPayload>('/auth/register', 'POST', {
    email: payload.email.trim(),
    password: payload.password,
    password_confirmation: payload.password_confirmation,
    first_name: payload.first_name.trim(),
    last_name: payload.last_name.trim(),
    ...(payload.phone?.trim() ? { phone: payload.phone.trim() } : {}),
  })

  return acceptSession(res.data)
}

/** Вход покупателя. Отказ приходит как 401 с кодом `bad_credentials`. */
export async function loginCustomer(email: string, password: string): Promise<CustomerUser> {
  const res = await send<AuthPayload>('/auth/login', 'POST', { email: email.trim(), password })

  return acceptSession(res.data)
}

/**
 * Выход из кабинета.
 *
 * `as: 'customer'` обязателен: без него запрос ушёл бы под токеном сотрудника
 * (он «важнее» по умолчанию), и кнопка «Выйти» в кабинете отзывала бы админскую
 * сессию, оставляя покупательскую живой. Локальная очистка выполняется в любом
 * случае — как и в админском `logout()`.
 */
export async function logoutCustomer(): Promise<void> {
  try {
    await request<unknown>('/auth/logout', { method: 'POST', as: 'customer' })
  } catch {
    /* токен всё равно стираем */
  }
  clearCustomerSession()
}

function acceptSession(payload: AuthPayload): CustomerUser {
  setCustomerToken(payload.token)
  saveCustomerUser(payload.user)
  // Токен только что выдан сервером — проверять его через `/me` незачем.
  validatedToken = payload.token

  return payload.user
}

/** `GET /me` — профиль из сессии (владелец не передаётся). */
export async function fetchMe(): Promise<CustomerUser> {
  const res = await get<CustomerUser>('/me')
  saveCustomerUser(res.data)

  return res.data
}

export interface ProfilePatch {
  first_name?: string
  last_name?: string
  email?: string
  phone?: string
}

/**
 * `PATCH /me` — правка профиля.
 *
 * Телефон отправляем только если он непустой: у `PATCH` поля `sometimes`, и
 * пустая строка — это не «стереть телефон», а значение, которое сервер
 * отбросит. Явная отправка `null` тоже не нужна — витрина не даёт стереть поле
 * отдельным действием.
 */
export async function updateMe(patch: ProfilePatch): Promise<CustomerUser> {
  const body: Record<string, string> = {}
  if (patch.first_name !== undefined) body.first_name = patch.first_name.trim()
  if (patch.last_name !== undefined) body.last_name = patch.last_name.trim()
  if (patch.email !== undefined) body.email = patch.email.trim()
  if (patch.phone !== undefined && patch.phone.trim() !== '') body.phone = patch.phone.trim()

  const res = await send<CustomerUser>('/me', 'PATCH', body)
  saveCustomerUser(res.data)

  return res.data
}

/**
 * `POST /me/password` — задать или сменить пароль.
 *
 * `current_password` отправляется только когда он передан: у аккаунта,
 * заведённого при покупке, пароля нет, и требовать «текущий» нечего — сервер
 * проверяет его ровно в этом случае (см. `ChangePasswordRequest`).
 *
 * ВАЖНО ДЛЯ ВЫЗЫВАЮЩЕГО: успешная смена отзывает ВСЕ сессии, включая текущую
 * (`UserService::updateUser()`), поэтому после вызова нужно снова войти. Это
 * правило проекта, а не побочный эффект.
 */
export async function changePassword(newPassword: string, currentPassword?: string): Promise<void> {
  await send<unknown>('/me/password', 'POST', {
    ...(currentPassword ? { current_password: currentPassword } : {}),
    password: newPassword,
    password_confirmation: newPassword,
  })
}

/** `GET /me/sessions` — активные сессии аккаунта. */
export async function fetchSessions(): Promise<CustomerSession[]> {
  const res = await get<CustomerSession[]>('/me/sessions')

  return Array.isArray(res.data) ? res.data : []
}

/** `DELETE /me/sessions/{id}` — завершить одну сессию. Чужая → 404. */
export async function revokeSession(id: string): Promise<void> {
  await send<unknown>(`/me/sessions/${encodeURIComponent(id)}`, 'DELETE')
}

/**
 * `GET /me/orders` — заказы текущего покупателя.
 *
 * Скоуп задаёт сервер (владелец берётся из сессии), поэтому клиент не передаёт
 * ни `user_id`, ни `email`: подставить чужое неоткуда.
 */
export async function fetchMyOrders(): Promise<CustomerOrder[]> {
  const res = await get<Array<Record<string, unknown>>>('/me/orders')
  const list = Array.isArray(res.data) ? res.data : []

  return list.map(normalizeOrder)
}

/**
 * Привести строку заказа к `CustomerOrder` (схема `Order` контракта).
 */
function normalizeOrder(raw: Record<string, unknown>): CustomerOrder {
  const totals = (raw.totals ?? {}) as { total_amount?: unknown; currency?: unknown }
  const items = Array.isArray(raw.items) ? (raw.items as Array<Record<string, unknown>>) : []

  const titles = items
    .map((item) => String(item.event_title ?? ''))
    .filter((title) => title !== '')

  return {
    id: String(raw.id ?? ''),
    number: String(raw.order_number ?? ''),
    status: String(raw.status ?? 'pending'),
    paymentStatus: raw.payment_status === undefined || raw.payment_status === null ? null : String(raw.payment_status),
    totalMinor: Number(totals.total_amount ?? 0) || 0,
    currency: String(totals.currency ?? 'RUB'),
    createdAt: String(raw.created_at ?? ''),
    itemsCount: items.length,
    eventTitles: titles,
    email: String(raw.customer_email ?? ''),
  }
}

/**
 * `POST /auth/password/forgot` — запросить ссылку для сброса пароля.
 *
 * Ответ ВСЕГДА 204, даже для незарегистрированного адреса: иначе эндпоинт
 * превращается в оракул «есть ли у нас такой покупатель». Поэтому UI не может и
 * не должен различать эти случаи — он показывает одно и то же сообщение.
 */
export async function forgotPassword(email: string): Promise<void> {
  await send<unknown>('/auth/password/forgot', 'POST', { email: email.trim() })
}

/** `POST /auth/password/reset` — задать новый пароль по токену из письма. */
export async function resetPassword(token: string, email: string, password: string): Promise<void> {
  await send<unknown>('/auth/password/reset', 'POST', {
    token,
    email,
    password,
    password_confirmation: password,
  })
}

/** Сообщение об ошибке для форм входа/регистрации. */
export function customerAuthMessage(e: unknown): string {
  const err = e as ApiError
  if (err?.status === 401) return 'Неверный e-mail или пароль.'
  if (err?.status === 429) return 'Слишком много попыток. Подождите минуту и повторите.'
  if (err?.status === 0) return 'Нет соединения с сервером. Проверьте связь.'
  return err?.message || 'Не удалось выполнить действие. Попробуйте ещё раз.'
}
