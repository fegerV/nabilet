/**
 * Платежи (витрина).
 *
 * Контракт:
 *   POST /api/v1/payments           → 201 { data: { payment, confirmation_url } }
 *                                     200 — тот же ключ идемпотентности (повтор)
 *   POST /api/v1/payments/demo-pay  → 200 { data: Payment }
 *
 * Два правила, из-за которых этот модуль существует отдельно от `inventory.ts`:
 *
 *  1. `order_id` — ULID (`orders.public_id`), а не числовой id. Именно ULID
 *     возвращает `POST /cart/checkout` в поле `order_id`, и именно его принимает
 *     `POST /payments` (`PaymentController::resolveOrder()`). Пока сервер требовал
 *     `integer`, витрина получала 422 на собственном ответе checkout.
 *
 *  2. Оплата гостевого заказа авторизуется заголовком `X-Cart-Token`, который
 *     подставляет `api.ts`. У гостевого заказа `user_id = NULL`, поэтому больше
 *     предъявить нечего; без заголовка сервер отвечает 404.
 */

import { get, send } from './api'

/** Ресурс платежа (подмножество, нужное витрине). */
export interface PaymentResource {
  id?: number | string
  public_id?: string
  status?: string
  provider?: string
  provider_payment_id?: string | null
  amount?: number | string
  currency?: string
  payment_url?: string | null
}

/** Статусы платежа из `ck_payments_status` (спек `payments`). */
export type PaymentStatus = 'pending' | 'waiting_for_capture' | 'succeeded' | 'canceled' | 'failed' | string

/** Дальше опрашивать сервер бессмысленно: решение принято. */
export const TERMINAL_PAYMENT_STATUSES: readonly string[] = ['succeeded', 'canceled', 'failed']

export function isTerminalPaymentStatus(status: string | undefined | null): boolean {
  return TERMINAL_PAYMENT_STATUSES.includes(String(status ?? '').toLowerCase())
}

export interface InitiatePaymentResult {
  payment: PaymentResource
  /** Куда отправить покупателя: страница провайдера либо локальный симулятор. */
  confirmation_url: string | null
}

/**
 * Инициировать оплату заказа.
 *
 * Ключ идемпотентности обязателен (S-07): сервер по нему распознаёт повторный
 * запрос. Без него двойной клик по кнопке «Оплатить» или ретрай уровня сети
 * создавал второй pending-платёж по тому же заказу. Ключ генерируется на одно
 * «намерение оплатить» (`newIdempotencyKey()`) и переиспользуется при повторах
 * ЭТОГО ЖЕ запроса.
 *
 * Сервер отвечает 201 при создании платежа и 200, когда вернул уже
 * существующий по тому же ключу, — тело ответа в обоих случаях одинаковое.
 */
export async function initiatePayment(
  orderId: string | number,
  idempotencyKey: string,
): Promise<InitiatePaymentResult> {
  const res = await send<InitiatePaymentResult>('/payments', 'POST', {
    order_id: orderId,
    idempotency_key: idempotencyKey,
  })

  return res.data
}

/**
 * Новый ключ идемпотентности платежа.
 *
 * Префикс `pay_` — чтобы ключ нельзя было спутать с ключом другого ресурса в
 * общем журнале. `crypto.randomUUID()` есть во всех целевых браузерах
 * (защищённый контекст); запасная ветка нужна только для http-origin на
 * localhost, где `crypto.randomUUID` может отсутствовать.
 */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `pay_${crypto.randomUUID()}`
  }

  return `pay_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 14)}`
}

/**
 * Подтвердить демо-платёж — симулятор ЮKassa, работает только при
 * `PAYMENT_DEMO_MODE=true` (иначе сервер отвечает 403 `DEMO_DISABLED`).
 *
 * ВАЖНО: `payment_id` здесь — это `provider_payment_id` («demo_…»), то самое
 * значение, которое сервер сам положил в `confirmation_url`. Не `public_id` и
 * не числовой `id`: `PaymentService::confirmDemoPayment()` требует префикс
 * `demo_` и ищёт платёж по `provider_payment_id`. Отправка `public_id` давала
 * 422 `NOT_DEMO_PAYMENT` — покупка проходила все шаги и рассыпалась на
 * последнем. Берите значение через `demoPaymentId()`, а не из ресурса.
 *
 * В реальном режиме этот вызов не используется: подтверждение приходит
 * вебхуком от провайдера, а покупатель просто возвращается на `return_url`.
 */
export async function confirmDemoPayment(paymentId: string): Promise<PaymentResource> {
  const res = await send<PaymentResource>('/payments/demo-pay', 'POST', { payment_id: paymentId })

  return res.data
}

/**
 * Идентификатор платежа для демо-подтверждения — ИЗ ССЫЛКИ, не из ресурса.
 *
 * Ссылку собирает сервер, и именно в ней лежит тот идентификатор, который
 * принимает `POST /payments/demo-pay` (`provider_payment_id`, см.
 * `PaymentService::confirmDemoPayment()`). Ресурс отдаёт ещё и `public_id` с
 * числовым `id` — оба для этого вызова неверны, и путаница между ними стоила
 * целой покупки: 422 вместо выданного билета.
 *
 * Фолбэк на `provider_payment_id` ресурса — на случай ссылки без параметра.
 */
export function demoPaymentId(
  confirmationUrl: string | null | undefined,
  payment?: PaymentResource | null,
): string {
  if (confirmationUrl) {
    try {
      const fromUrl = new URL(confirmationUrl, window.location.origin).searchParams.get('payment_id')
      if (fromUrl) return fromUrl
    } catch {
      /* ссылка некорректна — пробуем ресурс */
    }
  }

  return String(payment?.provider_payment_id ?? '')
}

/* ── Авторитетный статус (C2) ───────────────────────────────────────────────
 * Страница результата раньше доверяла параметру роута: неизвестное значение
 * (`#/payment/unknown-result`) показывало «Билеты готовы», потому что дефолтом
 * стоял success. Теперь экран спрашивает сервер, а параметр роута — только
 * подсказка. Доступ: сотрудник, владелец заказа либо гость с `X-Cart-Token`
 * (подставляет api.ts) — тот же capability, что у `POST /payments`.
 */

/** Прочитать платёж с сервера: `GET /payments/{payment}` → `{ data: Payment }`. */
export async function fetchPayment(paymentId: string | number): Promise<PaymentResource> {
  const res = await get<PaymentResource>(`/payments/${encodeURIComponent(String(paymentId))}`)

  return res.data
}

/**
 * Запомнить платёж в sessionStorage: уход на оплату — полная навигация, и по
 * возврату страница результата должна знать, ЧТО у сервера спрашивать.
 * sessionStorage, а не localStorage: сценарий живёт одной вкладкой.
 */
const PAYMENT_STORAGE_KEY = 'nabilet_last_payment'

export function rememberPaymentId(paymentId: string | number | null | undefined): void {
  try {
    const value = paymentId == null ? '' : String(paymentId)
    if (value) sessionStorage.setItem(PAYMENT_STORAGE_KEY, value)
    else sessionStorage.removeItem(PAYMENT_STORAGE_KEY)
  } catch {
    /* приватный режим — просто спросим по подсказке роута */
  }
}

export function recallPaymentId(): string | null {
  try {
    const value = sessionStorage.getItem(PAYMENT_STORAGE_KEY)

    return value && value !== '' ? value : null
  } catch {
    return null
  }
}

/**
 * Хэш нашей собственной страницы-симулятора оплаты.
 *
 * Маршрут `checkout/demo-pay` (`DemoPayPage`) — это не платежная страница
 * провайдера, а ЗАГЛУШКА на время отсутствия ключей ЮKassa: она существует
 * только при `PAYMENT_DEMO_MODE=true`. Отличить её от настоящего адреса
 * провайдера обязательно: на чужой сайт подтверждаться нельзя, а наш
 * собственный симулятор можно вызвать и прямо из виджета покупки, не заставляя
 * покупателя уходить со страницы (см. `usePurchaseFlow`).
 *
 * Один источник правды на оба места: `anchorDemoUrl()` переписывает адрес,
 * `isDemoConfirmation()` отвечает на вопрос «это наш симулятор?». Разъешись
 * они — заглушка увозила бы покупателя на полную навигацию без причины.
 */
export const DEMO_CONFIRM_HASH = '#/checkout/demo-pay'

/**
 * Ссылка подтверждения ведёт на НАШ демо-симулятор, а не к провайдеру.
 *
 * Без этого вопрос приходилось решать сравнением строк на каждом новом месте,
 * и первая же правка маршрута (`/checkout/demo-pay` → `/demo-pay`) молча
 * ломала покупку в один экран.
 */
export function isDemoConfirmation(confirmationUrl: string | null | undefined): boolean {
  if (!confirmationUrl) return false

  try {
    return new URL(confirmationUrl, window.location.origin).hash.startsWith(DEMO_CONFIRM_HASH)
  } catch {
    return false
  }
}

/**
 * Привести демо-ссылку подтверждения к текущему origin.
 *
 * Сервер собирает её из origin запроса, но если сайт открыт через другой
 * хост/порт или стоит за прокси с иным публичным адресом, ссылка уведёт
 * покупателя на чужой домен — и «оплата» закончится ничем. Демо-страница
 * принадлежит нам же, поэтому браузер — единственный, кто точно знает её адрес.
 *
 * Переписывается ТОЛЬКО наш собственный путь подтверждения: адрес реального
 * провайдера (например, `yookassa.ru`) трогать нельзя — это внешний сайт.
 */
export function anchorDemoUrl(confirmationUrl: string | null): string | null {
  if (!confirmationUrl) return null

  let parsed: URL
  try {
    parsed = new URL(confirmationUrl, window.location.origin)
  } catch {
    return confirmationUrl
  }

  if (isDemoConfirmation(confirmationUrl)) {
    return `${window.location.origin}${parsed.pathname}${parsed.search}${parsed.hash}`
  }

  return confirmationUrl
}
