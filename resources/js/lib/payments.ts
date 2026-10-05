/**
 * Платежи (витрина).
 *
 * Контракт:
 *   POST /api/v1/payments           → 201 { data: { payment, confirmation_url } }
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

import { send } from './api'

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

export interface InitiatePaymentResult {
  payment: PaymentResource
  /** Куда отправить покупателя: страница провайдера либо локальный симулятор. */
  confirmation_url: string | null
}

/**
 * Инициировать оплату заказа.
 *
 * Идемпотентность обеспечивает сервер: повторный вызов для заказа с уже
 * активным платежом возвращает ТОТ ЖЕ платёж и ту же ссылку, а не создаёт
 * второй (`PaymentService::initiatePayment()`), поэтому отдельный
 * `Idempotency-Key` на клиенте не нужен.
 */
export async function initiatePayment(orderId: string | number): Promise<InitiatePaymentResult> {
  const res = await send<InitiatePaymentResult>('/payments', 'POST', { order_id: orderId })

  return res.data
}

/**
 * Подтвердить демо-платёж — симулятор ЮKassa, работает только при
 * `PAYMENT_DEMO_MODE=true` (иначе сервер отвечает 403 `DEMO_DISABLED`).
 *
 * В реальном режиме этот вызов не используется: подтверждение приходит
 * вебхуком от провайдера, а покупатель просто возвращается на `return_url`.
 */
export async function confirmDemoPayment(paymentId: string): Promise<PaymentResource> {
  const res = await send<PaymentResource>('/payments/demo-pay', 'POST', { payment_id: paymentId })

  return res.data
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

  if (parsed.hash.startsWith('#/checkout/demo-pay')) {
    return `${window.location.origin}${parsed.pathname}${parsed.search}${parsed.hash}`
  }

  return confirmationUrl
}
