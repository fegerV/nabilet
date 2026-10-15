<script setup lang="ts">
/**
 * Результат оплаты.
 *
 * Формулировка состояния важнее картинки. Успех — «билеты готовы». Ошибка —
 * «деньги не списаны, попробуйте снова», потому что первая мысль пользователя
 * именно про деньги, а не про статус. В ожидании говорим, что подтверждение
 * придёт от сервера: это снимает вопрос «а точно ли прошло?».
 *
 * Сумма берётся из `cart.orderTotalMinor` — это значение из ответа
 * `POST /cart/checkout`, то есть серверная цена заказа (инвариант 6). Здесь
 * считалось `cart.subtotalMinor + 9900 - cart.discountMinor`: во-первых,
 * сервисного сбора сервер не берёт, во-вторых, `cart.clear()` на успехе
 * обнуляет `subtotalMinor`, поэтому экран показывал «Сумма к оплате 0 ₽» сразу
 * после отрисовки. `orderTotalMinor` стор намеренно не сбрасывает — и переживает
 * редирект на платёжную страницу через sessionStorage.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import { useCartStore } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import { ApiError } from '@/lib/api'
import { anchorDemoUrl, fetchPayment, initiatePayment, newIdempotencyKey, recallPaymentId, rememberPaymentId } from '@/lib/payments'
import { trackEvent } from '@/lib/metrika'
import { money } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const ui = useUiStore()

/**
 * Ключ идемпотентности для повторной оплаты (S-07).
 *
 * Это НОВАЯ попытка, а не повтор прежнего запроса, поэтому ключ новый — иначе
 * сервер вернул бы платёж прошлой попытки вместе с её уже нерабочей ссылкой.
 * Внутри одной попытки ключ стабилен: двойной клик по «Попробовать снова» не
 * создаёт второй платёж.
 */
const retryKey = ref('')

function idempotencyKey(): string {
  if (!retryKey.value) retryKey.value = newIdempotencyKey()
  return retryKey.value
}

const VIEW = {
  success: {
    icon: '✓',
    tone: 'bg-mint-500/15 text-mint-400 ring-mint-500/30',
    title: 'Билеты готовы',
    text: 'Оплата подтверждена сервером. Билеты уже в разделе «Мои билеты» и летят на почту — QR работает и без интернета.',
    primary: 'Показать билеты',
    to: '/tickets',
  },
  pending: {
    icon: '⏱',
    tone: 'bg-sun-500/15 text-sun-400 ring-sun-500/30',
    title: 'Ждём подтверждения',
    text: 'Платёж обрабатывается. Статус подтвердит сервер — обычно это занимает меньше минуты, страницу можно закрыть.',
    primary: 'Обновить статус',
    to: '/tickets',
  },
  fail: {
    icon: '!',
    tone: 'bg-rose-500/15 text-rose-400 ring-rose-500/30',
    title: 'Оплата не прошла',
    text: 'Деньги не списаны. Места пока за вами — можно повторить оплату или выбрать другой способ.',
    primary: 'Попробовать снова',
    to: '/checkout',
  },
} as const

type ResultKey = keyof typeof VIEW

/**
 * Параметр роута — ПОДСКАЗКА, а не истина (C2).
 *
 * Раньше неизвестное значение (`#/payment/unknown-result`) откатывалось к
 * `VIEW.success`, и покупатель видел «Билеты готовы» без единого запроса к
 * серверу. Теперь дефолт — `pending`, а решение принимает статус платежа,
 * полученный с сервера.
 */
const routeHint = computed<ResultKey>(() => {
  const raw = String(route.params.result ?? '').toLowerCase()
  return raw in VIEW ? (raw as ResultKey) : 'pending'
})

const result = ref<ResultKey>(routeHint.value)
const checking = ref(false)
const retrying = ref(false)
const view = computed(() => VIEW[result.value])

/** Сумма заказа. `0` только если заказ не создавался в этой вкладке. */
const paidMinor = computed(() => cart.orderTotalMinor)

/** Статус платежа с сервера → состояние экрана. Больше ниоткуда. */
function statusToResult(status: string | undefined | null): ResultKey {
  const normalized = String(status ?? '').toLowerCase()
  if (normalized === 'succeeded') return 'success'
  if (normalized === 'canceled' || normalized === 'failed') return 'fail'
  return 'pending'
}

/**
 * Спросить сервер о статусе платежа — единственный источник истины.
 *
 * Без запоминаемого payment_id (прямой заход по ссылке, другая вкладка)
 * остаёмся на подсказке роута: выдумывать статус нечем. Кнопка «Обновить
 * статус» в режиме ожидания делает ровно этот вызов — раньше она уводила на
 * `/tickets`, то есть не опрашивала ничего.
 */
async function refreshStatus(): Promise<void> {
  const paymentId = recallPaymentId()
  if (!paymentId) return

  checking.value = true
  try {
    const payment = await fetchPayment(paymentId)
    result.value = statusToResult(payment.status)
    // Успех закрывает сценарий только когда его подтвердил сервер.
    // Метрику опрашиваем ДО clear(): он обнуляет seats/count, а количество
    // билетов — часть цели `purchase` для Директа.
    if (result.value === 'success') {
      reportOutcome('success')
      cart.clear()
    } else if (result.value === 'fail') {
      reportOutcome('fail')
    }
  } catch {
    // 404 — платёж не найден или не наш; сеть/401 — сессия/сбой. В обоих
    // случаях статус НЕ подтверждён, поэтому экран остаётся в ожидании,
    // а не переходит к «Билеты готовы».
    result.value = result.value === 'fail' ? 'pending' : result.value
  } finally {
    checking.value = false
  }
}

/**
 * E2: сообщить в Метрику исход оплаты — один раз на страницу.
 *
 * Цели `payment_success` (она же `purchase` — главная для автостратегий
 * Директа) и `payment_fail` существовали в config/metrika.php, но не имели
 * вызовов. Ограничение «один раз» обязательно: автоопрос дёргает
 * `refreshStatus()` каждые 5 секунд, и без флага Директ получил бы дюжину
 * «покупок» на один заказ.
 */
let outcomeReported: 'success' | 'fail' | null = null
function reportOutcome(outcome: 'success' | 'fail'): void {
  if (outcomeReported) return
  outcomeReported = outcome
  if (outcome === 'success') {
    // revenue — в РУБЛЯХ: `paidMinor` в копейках (контракт money()).
    void trackEvent('payment_success', {
      revenue: Math.round(paidMinor.value / 100),
      orderId: cart.orderId ?? undefined,
      items: cart.count,
    }).catch(() => {})
    return
  }
  void trackEvent('payment_fail', { orderId: cart.orderId ?? undefined }).catch(() => {})
}

/** Ограниченный автоопрос: страница сама проверяет статус, пока платёж в пути. */
const POLL_INTERVAL_MS = 5000
const POLL_MAX_TICKS = 12
let pollTimer: number | null = null

function stopPolling(): void {
  if (pollTimer !== null) {
    window.clearInterval(pollTimer)
    pollTimer = null
  }
}

function startPolling(): void {
  stopPolling()
  let ticks = 0
  pollTimer = window.setInterval(() => {
    ticks += 1
    if (ticks > POLL_MAX_TICKS) {
      stopPolling()
      return
    }
    void refreshStatus().then(() => {
      if (result.value !== 'pending') stopPolling()
    })
  }, POLL_INTERVAL_MS)
}

/**
 * Повторить оплату ТОГО ЖЕ заказа.
 *
 * Раньше кнопка вела на `/checkout` без `session`, и экран отвечал «корзина
 * пуста»: после полной навигации на оплату сеанс уже неоткуда взять. Заказ на
 * сервере существует, поэтому правильнее начать платёж по нему — сервер
 * идемпотентно вернёт активный платёж и его ссылку.
 */
async function retryPayment(): Promise<void> {
  const orderId = cart.orderId
  if (!orderId) {
    router.push('/')
    return
  }

  retrying.value = true
  try {
    const { confirmation_url, payment } = await initiatePayment(orderId, idempotencyKey())
    // C2: запоминаем платёж повторной попытки — иначе после ухода на оплату
    // страница результата не будет знать, что спрашивать у сервера.
    rememberPaymentId(payment?.public_id ?? payment?.id)
    if (!confirmation_url) {
      ui.notify('sun', 'Нет ссылки на оплату', 'Попробуйте ещё раз через минуту.')
      return
    }
    window.location.href = anchorDemoUrl(confirmation_url) ?? confirmation_url
  } catch (error) {
    // Попытка завершилась ошибкой — следующая должна быть новым запросом.
    retryKey.value = ''
    ui.notify('rose', 'Оплата не началась', error instanceof ApiError ? error.message : 'Попробуйте ещё раз')
  } finally {
    retrying.value = false
  }
}

function onPrimary(): void {
  if (result.value === 'fail' && cart.orderId) {
    void retryPayment()
    return
  }
  // В ожидании главная кнопка — опрос сервера, а не уход на билеты.
  if (result.value === 'pending') {
    void refreshStatus()
    return
  }
  router.push(view.value.to)
}

onMounted(() => {
  void refreshStatus().then(() => {
    // Платёж ещё в пути — продолжается вебхуком/подтверждением: опрашиваем сами.
    if (result.value === 'pending' && recallPaymentId()) startPolling()
  })
})

onUnmounted(stopPolling)
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-10 sm:px-6">
    <div class="mx-auto max-w-md text-center">
      <div
        :class="['mx-auto grid h-16 w-16 place-items-center rounded-2xl text-2xl ring-1', view.tone]"
        aria-hidden="true"
      >{{ view.icon }}</div>

      <h1 class="mt-5 text-2xl font-bold tracking-tight text-content">{{ view.title }}</h1>
      <p class="mt-2 text-pretty text-base text-muted">{{ view.text }}</p>

      <div v-if="result === 'success' && paidMinor > 0" class="surface-card mt-6 p-4 text-left">
        <p class="text-xs uppercase tracking-wide text-subtle">Сумма к оплате</p>
        <p class="mt-0.5 text-2xl font-bold tabular-nums text-content">{{ money(paidMinor) }}</p>
        <p v-if="cart.orderId" class="mt-1 text-2xs text-subtle">Заказ {{ cart.orderId }}</p>
      </div>

      <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        <NButton variant="accent" size="lg" :loading="retrying || checking" @click="onPrimary">{{ view.primary }}</NButton>
        <NButton variant="secondary" size="lg" @click="router.push('/')">На афишу</NButton>
      </div>

      <p class="mt-6 text-xs text-subtle">
        Возник вопрос? Напишите в поддержку — номер заказа в письме.
      </p>
    </div>
  </div>
</template>
