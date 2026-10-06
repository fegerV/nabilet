<script setup lang="ts">
/**
 * Оформление заказа.
 *
 * Правила, важные для конверсии:
 *  - Один экран, а не пять шагов. Шаги показаны как прогресс, но не рвут поток:
 *    пользователь видит, сколько осталось, и не теряет введённое.
 *  - Ошибка валидации объясняет, что исправить («телефон — 11 цифр», а не
 *    «неверный формат»), и появляется у поля, а не в общей плашке.
 *  - Кнопка оплаты не блокируется до полного заполнения: она показывает, чего
 *    не хватает. Серая кнопка без объяснения — главная причина брошенных корзин.
 *
 * Здесь же происходит НАСТОЯЩАЯ покупка. Раньше на этом экране стояла имитация
 * (`setTimeout` + `router.push('/payment/success')`), поэтому до сервера не
 * доходил ни один заказ витрины: корзина оставалась `active`, места — `held`,
 * билеты не выпускались, а покупатель видел «оплата прошла». Теперь:
 *
 *   1. `POST /cart/checkout` — заказ из удержанных мест (нужны контакты: на
 *      e-mail уходит билет, поэтому валидация контактов идёт ДО создания заказа);
 *   2. `POST /payments` — платёж и `confirmation_url`;
 *   3. переход на страницу оплаты (в demo-режиме — на локальный симулятор,
 *      который подтверждает платёж и возвращает покупателя на результат).
 *
 * Цена и итог берутся из ответа сервера (инвариант 6). Здесь стояли
 * `SERVICE_FEE = 9900` и промокод `NABILET10`, считавший скидку на клиенте: ни
 * сбора, ни промокодов сервер не знает (`CartService::checkout()`: subtotal =
 * discount = fee = total = сумма позиций, а `promo_code` отвечает 422
 * `PROMO_CODE_NOT_SUPPORTED`). Покупатель видел сумму, которую с него не
 * списывали, — расхождение между экраном и чеком.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NCountdown from '@/components/ui/NCountdown.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { useCartStore } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import { checkoutSession, fetchCart } from '@/lib/inventory'
import { ApiError } from '@/lib/api'
import { anchorDemoUrl, initiatePayment } from '@/lib/payments'
import { money, ticketsLabel } from '@/lib/format'
import { cn } from '@/lib/cn'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const ui = useUiStore()

/** Сеанс, к которому относится корзина. Передаётся из выбора мест. */
const sessionId = computed(() => String(route.query.session ?? ''))

const STEPS = ['Места', 'Контакты', 'Оплата']
const currentStep = 1

const name = ref('')
const email = ref('')
const phone = ref('')
const agree = ref(false)
const paying = ref(false)
const submitted = ref(false)
/** Ошибки полей из §66-конверта сервера: `error.errors` → по полю. */
const serverErrors = ref<Record<string, string>>({})
/** Идёт восстановление корзины из `GET /cart` (F5 или возврат с оплаты). */
const restoring = ref(false)

const localErrors = computed(() => {
  const out: Record<string, string> = {}
  if (name.value.trim().length < 2) out.name = 'Укажите имя, как в документе'
  if (!/^[^@\s]+@[^@\s]+\.[a-zа-я]{2,}$/i.test(email.value)) out.email = 'Нужен e-mail — на него придёт билет'
  if (phone.value.replace(/\D/g, '').length !== 11) out.phone = 'Телефон — 11 цифр, начиная с 7'
  if (!agree.value) out.agree = 'Без согласия на оферту билет не выпустим'
  return out
})

/** Серверные ошибки важнее локальных: их вернул источник истины. */
const errors = computed<Record<string, string>>(() => ({ ...localErrors.value, ...serverErrors.value }))

const canPay = computed(() => Object.keys(errors.value).length === 0 && cart.count > 0)
const missingHint = computed(() => {
  if (cart.count === 0) return 'Сначала выберите места'
  const first = Object.values(errors.value)[0]
  return first ?? ''
})

/** Итог — сумма позиций. Именно её выставляет сервер (`orders.total_amount`). */
const totalMinor = computed(() => Math.max(0, cart.subtotalMinor))

/** Имена полей §66 → имена полей формы. */
const SERVER_FIELD_MAP: Record<string, string> = {
  customer_name: 'name',
  customer_email: 'email',
  customer_phone: 'phone',
}

/**
 * Перечитать серверную корзину.
 *
 * Стор корзины живёт в памяти, поэтому F5 на этом шаге (и возврат с платёжной
 * страницы) рисовал «корзина пуста» поверх реально удержанных мест. Читаем
 * `GET /cart` — ровно так же, как это делает экран выбора мест.
 */
async function restoreCart(): Promise<void> {
  const sid = sessionId.value
  if (!sid || cart.count > 0) return
  restoring.value = true
  try {
    const serverCart = await fetchCart(sid)
    if (!serverCart) return

    const items = (serverCart.items ?? []).flatMap((item) => {
      const id = String(item.inventory_item?.id ?? '')
      if (!id) return []
      return [
        {
          id,
          sector: String(item.inventory_item?.seat?.sector ?? 'Зал'),
          row: Number(item.inventory_item?.seat?.row ?? 0),
          number: Number(item.inventory_item?.seat?.number ?? 0),
          priceMinor: Number(item.unit_price ?? 0),
          kind: 'standard' as const,
        },
      ]
    })

    cart.hydrate(items)
    if (serverCart.expires_at) cart.setHoldExpiry(serverCart.expires_at)
  } catch {
    /* корзина недоступна — экран честно покажет «корзина пуста» */
  } finally {
    restoring.value = false
  }
}

function applyServerErrors(error: ApiError): void {
  const mapped: Record<string, string> = {}
  for (const [field, messages] of Object.entries(error.details ?? {})) {
    const key = SERVER_FIELD_MAP[field]
    if (key && Array.isArray(messages) && messages.length > 0) mapped[key] = String(messages[0])
  }
  serverErrors.value = mapped
}

function handlePayError(error: unknown): void {
  if (!(error instanceof ApiError)) {
    ui.notify('rose', 'Оплата не началась', error instanceof Error ? error.message : 'Попробуйте ещё раз')
    return
  }

  // Ошибки полей — у полей, а не общей плашкой.
  if (error.status === 422 && error.details) {
    applyServerErrors(error)
    ui.notify('sun', 'Проверьте данные', 'Некоторые поля заполнены неверно.')
    return
  }

  // Холд истёк: места уже освобождены, выбор нужно повторить.
  if (error.code === 'CART_EXPIRED' || error.status === 410) {
    ui.notify('rose', 'Время удержания истекло', 'Места освобождены — выберите их заново.')
    cart.release()
    void restoreCart()
    return
  }

  if (error.code === 'CART_EMPTY') {
    ui.notify('rose', 'Корзина пуста', 'Сервер не нашёл удержанных мест для этого сеанса.')
    void restoreCart()
    return
  }

  if (error.code === 'SALES_CLOSED') {
    ui.notify('rose', 'Продажи закрыты', error.message)
    return
  }

  if (error.code === 'PROMO_CODE_NOT_SUPPORTED') {
    // Промокод мы больше не отправляем; ветка оставлена, чтобы серверный отказ
    // был понятен, если поле когда-нибудь вернётся в форму.
    ui.notify('sun', 'Промокоды не поддерживаются', error.message)
    return
  }

  if (error.code === 'DEMO_DISABLED' || error.status === 403) {
    ui.notify('rose', 'Оплата недоступна', 'Платёжный провайдер не настроен — обратитесь к организатору.')
    return
  }

  ui.notify('rose', 'Оплата не началась', error.message)
}

async function pay(): Promise<void> {
  submitted.value = true
  serverErrors.value = {}

  if (!canPay.value) {
    ui.notify('sun', 'Не хватает данных', missingHint.value)
    return
  }
  if (!sessionId.value) {
    ui.notify('rose', 'Сеанс не определён', 'Вернитесь к событию и выберите места заново.')
    return
  }

  paying.value = true
  try {
    // 1. Заказ из удержанных мест. Контакты обязательны — на e-mail уходит билет.
    const { data: order } = await checkoutSession(sessionId.value, {
      customer_name: name.value.trim(),
      customer_email: email.value.trim(),
      customer_phone: phone.value.trim(),
    })

    // 2. Итог — из ответа сервера, он источник истины о цене.
    cart.setOrder(order.order_id, Number(order.total_amount))

    // 3. Платёж. Гостевой заказ авторизуется заголовком X-Cart-Token,
    //    который подставляет api.ts (`PaymentController::mayPay()`).
    const { confirmation_url } = await initiatePayment(order.order_id)

    if (!confirmation_url) {
      ui.notify('sun', 'Нет ссылки на оплату', 'Заказ создан. Попробуйте оплатить ещё раз.')
      await router.push('/payment/pending')
      return
    }

    // Уход на оплату — полная навигация, как в реальном сценарии с провайдером.
    window.location.href = anchorDemoUrl(confirmation_url) ?? confirmation_url
  } catch (error) {
    handlePayError(error)
  } finally {
    paying.value = false
  }
}

onMounted(() => {
  cart.setSession(sessionId.value)
  void restoreCart()
})
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight text-content">Оформление заказа</h1>

    <!-- Прогресс: пользователь видит, что осталось, но поток не прерывается -->
    <ol class="mt-4 flex items-center gap-2 text-xs">
      <li v-for="(step, i) in STEPS" :key="step" class="flex items-center gap-2">
        <span
          :class="
            cn(
              'grid h-6 w-6 place-items-center rounded-full text-2xs font-semibold',
              i < currentStep ? 'bg-brand-500 text-white' : i === currentStep ? 'bg-accent-500 text-white' : 'bg-surface-3 text-subtle',
            )
          "
        >{{ i < currentStep ? '✓' : i + 1 }}</span>
        <span :class="i === currentStep ? 'font-medium text-content' : 'text-subtle'">{{ step }}</span>
        <span v-if="i < STEPS.length - 1" class="h-px w-6 bg-line" aria-hidden="true" />
      </li>
    </ol>

    <!-- Возврат с платёжной страницы / F5: подтягиваем удержанные места -->
    <p v-if="restoring" class="mt-6 text-sm text-subtle">Восстанавливаем ваш выбор…</p>

    <NEmptyState
      v-else-if="cart.count === 0"
      class="surface-card mt-6"
      icon="◫"
      title="Корзина пуста"
      description="Вернитесь к событию и выберите места на схеме зала — они будут держаться за вами 10 минут."
      action-label="К афише"
      @action="router.push('/')"
    />

    <div v-else class="mt-6 grid gap-5 lg:grid-cols-[1fr_380px]">
      <div class="space-y-4">
        <!-- Контакты -->
        <section class="surface-card p-4">
          <h2 class="text-sm font-semibold text-content">Покупатель</h2>
          <p class="mt-0.5 text-xs text-subtle">Билет придёт на этот e-mail и останется в личном кабинете</p>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <NInput
              v-model="name"
              label="Имя и фамилия"
              placeholder="Иван Петров"
              autocomplete="name"
              required
              :error="submitted ? errors.name : ''"
            />
            <NInput
              v-model="phone"
              label="Телефон"
              placeholder="+7 900 123-45-67"
              type="tel"
              inputmode="tel"
              autocomplete="tel"
              required
              :error="submitted ? errors.phone : ''"
            />
            <NInput
              v-model="email"
              label="E-mail"
              placeholder="ivan@example.com"
              type="email"
              inputmode="email"
              autocomplete="email"
              required
              class="sm:col-span-2"
              :error="submitted ? errors.email : ''"
            />
          </div>
        </section>

        <!-- Способ получения -->
        <section class="surface-card p-4">
          <h2 class="text-sm font-semibold text-content">Как получить билет</h2>
          <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <label
              class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-brand-500 bg-brand-500/8 p-3"
            >
              <input type="radio" name="delivery" value="qr" checked class="mt-0.5 accent-brand-500" />
              <span>
                <span class="block text-sm font-medium text-content">QR в приложении</span>
                <span class="mt-0.5 block text-xs text-subtle">Покажете на входе, работает без сети</span>
              </span>
            </label>
            <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-line p-3 hover:border-line-strong">
              <input type="radio" name="delivery" value="pdf" class="mt-0.5 accent-brand-500" />
              <span>
                <span class="block text-sm font-medium text-content">PDF на почту</span>
                <span class="mt-0.5 block text-xs text-subtle">Плюс дубль в Telegram, если подключён</span>
              </span>
            </label>
          </div>
        </section>

        <!-- Согласие -->
        <section class="surface-card p-4">
          <NCheckbox
            v-model="agree"
            label="Согласен с офертой и правилами посещения"
            description="Возврат возможен не позднее чем за 24 часа до начала сеанса"
          />
          <p v-if="submitted && errors.agree" class="mt-2 text-xs text-rose-400">{{ errors.agree }}</p>
        </section>
      </div>

      <!-- Итог -->
      <aside class="lg:sticky lg:top-24 lg:self-start">
        <div class="surface-card overflow-hidden">
          <div class="border-b border-line px-4 py-3">
            <h2 class="text-sm font-semibold text-content">Заказ</h2>
          </div>

          <div class="p-4">
            <NCountdown
              v-if="cart.holdSecondsLeft > 0"
              :seconds-left="cart.holdSecondsLeft"
              :total="900"
              class="mb-4"
              @extend="cart.extendHold()"
            />

            <p v-if="cart.holdExtendError" class="mb-4 text-xs text-rose-400">
              {{ cart.holdExtendError }}
            </p>

            <ul class="space-y-2">
              <li
                v-for="seat in cart.seats.filter((s) => cart.selectedIds.has(s.id))"
                :key="seat.id"
                class="flex items-center justify-between gap-2 text-sm"
              >
                <span class="truncate text-muted">
                  Ряд {{ seat.row }}, место {{ seat.number }}
                  <span class="text-subtle">· {{ seat.sector }}</span>
                </span>
                <span class="flex-none tabular-nums text-content">{{ money(seat.priceMinor) }}</span>
              </li>
            </ul>

            <dl class="mt-4 space-y-1.5 border-t border-line pt-3 text-sm">
              <div class="flex justify-between">
                <dt class="text-muted">{{ ticketsLabel(cart.count) }}</dt>
                <dd class="tabular-nums text-content">{{ money(cart.subtotalMinor) }}</dd>
              </div>
              <div class="flex items-baseline justify-between border-t border-line pt-2">
                <dt class="font-medium text-content">Итого</dt>
                <dd class="text-xl font-semibold tabular-nums text-content">{{ money(totalMinor) }}</dd>
              </div>
            </dl>

            <NButton
              variant="accent"
              size="lg"
              block
              class="mt-4"
              :loading="paying"
              @click="pay"
            >
              Оплатить {{ money(totalMinor) }}
            </NButton>

            <p v-if="submitted && !canPay" class="mt-2 text-center text-xs text-sun-400">
              {{ missingHint }}
            </p>
            <p class="mt-3 text-center text-2xs text-subtle">
              Оплата проходит через ЮKassa. Статус заказа подтверждает сервер, а не редирект.
            </p>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
