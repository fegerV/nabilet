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
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NCountdown from '@/components/ui/NCountdown.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { useCartStore } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import { money, ticketsLabel } from '@/lib/format'
import { cn } from '@/lib/cn'

const router = useRouter()
const cart = useCartStore()
const ui = useUiStore()

const SERVICE_FEE = 9900
const STEPS = ['Места', 'Контакты', 'Оплата']
const currentStep = 1

const name = ref('')
const email = ref('')
const phone = ref('')
const agree = ref(false)
const promoInput = ref('')
const paying = ref(false)
const submitted = ref(false)

const errors = computed(() => {
  const out: Record<string, string> = {}
  if (name.value.trim().length < 2) out.name = 'Укажите имя, как в документе'
  if (!/^[^@\s]+@[^@\s]+\.[a-zа-я]{2,}$/i.test(email.value)) out.email = 'Нужен e-mail — на него придёт билет'
  if (phone.value.replace(/\D/g, '').length !== 11) out.phone = 'Телефон — 11 цифр, начиная с 7'
  if (!agree.value) out.agree = 'Без согласия на оферту билет не выпустим'
  return out
})

const canPay = computed(() => Object.keys(errors.value).length === 0 && cart.count > 0)
const missingHint = computed(() => {
  if (cart.count === 0) return 'Сначала выберите места'
  const first = Object.values(errors.value)[0]
  return first ?? ''
})

const totalMinor = computed(() => Math.max(0, cart.subtotalMinor + SERVICE_FEE - cart.discountMinor))

function applyPromo(): void {
  const code = promoInput.value.trim().toUpperCase()
  if (!code) return
  if (code === 'NABILET10') {
    cart.applyPromo(code, Math.round(cart.subtotalMinor * 0.1))
    ui.notify('mint', 'Промокод применён', 'Скидка 10% от стоимости билетов')
  } else {
    ui.notify('rose', 'Промокод не действует', 'Проверьте написание или срок действия')
  }
}

async function pay(): Promise<void> {
  submitted.value = true
  if (!canPay.value) {
    ui.notify('sun', 'Не хватает данных', missingHint.value)
    return
  }
  paying.value = true
  // Имитация запроса к /api/v1/payments. Реальный вызов уйдёт с Idempotency-Key.
  await new Promise((resolve) => setTimeout(resolve, 1100))
  paying.value = false
  router.push('/payment/success')
}
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

    <NEmptyState
      v-if="cart.count === 0"
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
              class="mb-4"
              @extend="cart.extendHold()"
            />

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
              <div class="flex justify-between">
                <dt class="text-muted">Сервисный сбор</dt>
                <dd class="tabular-nums text-content">{{ money(SERVICE_FEE) }}</dd>
              </div>
              <div v-if="cart.discountMinor > 0" class="flex justify-between">
                <dt class="text-mint-400">Промокод {{ cart.promoCode }}</dt>
                <dd class="tabular-nums text-mint-400">−{{ money(cart.discountMinor) }}</dd>
              </div>
              <div class="flex items-baseline justify-between border-t border-line pt-2">
                <dt class="font-medium text-content">Итого</dt>
                <dd class="text-xl font-semibold tabular-nums text-content">{{ money(totalMinor) }}</dd>
              </div>
            </dl>

            <!-- Промокод -->
            <div class="mt-3 flex gap-2">
              <NInput v-model="promoInput" placeholder="Промокод" class="flex-1" aria-label="Промокод" />
              <NButton variant="secondary" class="flex-none" @click="applyPromo">Применить</NButton>
            </div>

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
