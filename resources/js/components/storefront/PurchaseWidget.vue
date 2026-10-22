<script setup lang="ts">
/**
 * Покупка билетов в один экран.
 *
 * ПОЧЕМУ ВИДЖЕТ, А НЕ ЕЩЁ ОДНА СТРАНИЦА
 *   Раньше покупка шла по цепочке «событие → схема зала → корзина →
 *   оформление → оплата»: пять экранов, пять загрузок, пять адресов. Претензия
 *   была одной фразой — «купить должно быть легко, а не 10 страниц загрузить».
 *   Теперь весь контур на одном экране и без маршрутов:
 *     схема зала → клик по месту = выбор → «Далее» → контакты и оплата.
 *
 *   Денежная часть — `usePurchaseFlow` (без разметки), холды — `useSeatHolds`,
 *   инвентарь — `useSeatInventory`. Здесь остаётся показ.
 *
 * ПОЧЕМУ ЭТО КОМПОНЕНТ, А НЕ СТРАНИЦА
 *   Страница события принадлежит витрине: у неё шапка, подвал, SEO. А покупка
 *   должна работать и отдельно — встроенной рамкой на чужом сайте, где наших
 *   маршрутов нет вообще. Поэтому покупка не обращается к `useRouter` и не
 *   меняет адрес: её можно вызвать из любого места.
 *
 * СМЕНА СЕАНСА
 *   Холды серверные и привязаны к сеансу. При переключении холды ПРОШЛОГО
 *   сеанса снимаются (`releaseSeat`) до загрузки нового — иначе сводка
 *   показывала бы места другого сеанса, а checkout отвечал бы «корзина пуста».
 *   Обратный порядок (снять после) слал бы DELETE по новому session_id и
 *   получал 404.
 */
import { computed, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NCountdown from '@/components/ui/NCountdown.vue'
import CoordSeatMap from '@/components/seat/CoordSeatMap.vue'
import OrderSummary from '@/components/seat/OrderSummary.vue'
import { seatLineLabel, seatLineTotal, useCartStore } from '@/stores/cart'
import { useLoyaltyStore } from '@/stores/loyalty'
import { useUiStore } from '@/stores/ui'
import { money, dateFull, time, seatsLabel, ticketsLabel } from '@/lib/format'
import { releaseSeat } from '@/lib/inventory'
import { customerUser, logoutCustomer } from '@/lib/customer'
import { useSeatInventory } from '@/pages/storefront/useSeatInventory'
import { useSeatHolds } from '@/pages/storefront/useSeatHolds'
import { usePurchaseFlow } from '@/pages/storefront/usePurchaseFlow'

/**
 * Сеанс в объёме, нужном виджету.
 *
 * Своя форма, а не `SessionItem` страницы события: компонент не должен зависеть
 * от того, какими ещё полями располагает карточка мероприятия. Обе стороны
 * сходятся структурно — страница отдаёт свои сеансы как есть.
 */
export interface WidgetSession {
  id: string
  starts_at?: string
  startsAt?: string
  hall?: string
  hall_name?: string
  available_seats?: number
  availableSeats?: number
  status?: string
}

const props = withDefaults(
  defineProps<{
    /** Сеансы события: из них покупатель выбирает дату и время. */
    sessions: WidgetSession[]
    /** Активный сеанс (v-model). */
    sessionId: string
    /** Название площадки — в подписи над схемой («Театр СурГУ, Основной зал»). */
    venueName?: string
  }>(),
  { venueName: '' },
)

const emit = defineEmits<{ 'update:sessionId': [id: string] }>()

const cart = useCartStore()
const loyalty = useLoyaltyStore()
const ui = useUiStore()

/* Активный сеанс — свой ref: переключение идёт через снятие холдов. */
const activeId = ref(props.sessionId)

const startsAtOf = (s: WidgetSession): string => s.starts_at ?? s.startsAt ?? ''
const availableOf = (s: WidgetSession): number => s.available_seats ?? s.availableSeats ?? 0
const hallOf = (s: WidgetSession): string => s.hall_name ?? s.hall ?? ''

const activeSession = computed<WidgetSession | undefined>(
  () => props.sessions.find((s) => s.id === activeId.value),
)

const {
  inventory,
  loading,
  loadError,
  truncated,
  totalSeats,
  maxTickets,
  stageSide,
  useCoordMap,
  fetchSeats,
} = useSeatInventory()

const sessionId = computed(() => activeId.value)

const purchase = usePurchaseFlow({ sessionId, onAbandonSelection: () => load() })

/*
 * Реактивные значения разворачиваем здесь, а не тянем через `purchase.`:
 * в шаблоне автораспаковка ref работает только для КОРНЕВЫХ связей setup —
 * поле объекта остаётся Ref'ом (проверено typecheck: `Ref<string>` не
 * присваивается в `v-model`). Методы вызываем и так, и так — это функции.
 */
const {
  step,
  outcome,
  name,
  email,
  emailConfirm,
  phone,
  agree,
  useBonuses,
  paying,
  submitted,
  errors,
  canPay,
  emailOk,
  missingHint,
  loggedIn,
  bonusMinor,
  totalMinor,
  canUseBonuses,
  bonusLabel,
  bonusHint,
} = purchase

const { hydrateCart, onToggleCoord, onLimit, remove } = useSeatHolds({
  sessionId,
  inventory,
  maxTickets,
  // `load` объявлена ниже — ссылка ленивая, вызов происходит позже.
  reload: () => load(),
})

/* ── Загрузка сеанса ───────────────────────────────────────────────────────
 * Порядок существенен: `hydrateCart` сопоставляет позиции серверной корзины с
 * УЖЕ загруженным инвентарём, поэтому обязан идти после `fetchSeats`.
 */
async function load(): Promise<void> {
  const sid = activeId.value
  if (!sid) {
    loadError.value = 'Сеанс не указан'
    loading.value = false
    return
  }

  loading.value = true
  loadError.value = null
  cart.setSession(sid)

  try {
    await fetchSeats(sid)
    await hydrateCart(sid)
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

/**
 * Снять холды предыдущего сеанса и очистить выбор.
 *
 * Ошибка снятия НЕ глушится: если сервер не отпустил холд, место висит
 * занятым за покупателем, и он должен об этом знать — иначе он будет думать,
 * что освободил его, и злиться на «занятые» места.
 */
async function releaseAll(sid: string): Promise<void> {
  for (const id of [...cart.selectedIds]) {
    const cartItem = cart.meta[id]
    if (!cartItem) continue
    try {
      await releaseSeat(sid, cartItem)
    } catch {
      ui.notify('sun', 'Место могло остаться занятым', 'Сервер не подтвердил снятие холда при смене сеанса.')
    }
  }
  cart.clear()
}

async function switchTo(nextId: string): Promise<void> {
  if (nextId === '' || nextId === activeId.value) return
  const previous = activeId.value
  // Форма контактов относится к этому сеансу — вместе со сменой сеанса она
  // закрывается, иначе покупатель дописал бы данные к другому заказу.
  purchase.backToSeats()
  await releaseAll(previous)
  activeId.value = nextId
  emit('update:sessionId', nextId)
  await load()
}

/* Активный сеанс приходит и снаружи: кнопки сеансов живут на странице. */
watch(
  () => props.sessionId,
  (next) => {
    if (next !== activeId.value) void switchTo(next)
  },
)

/* Бонусы нужны авторизованному, но запрос безопасен для гостя: стор сам
 * вернёт пустой баланс, и «кошелька» покупатель не увидит. */
void loyalty.load()

watch(activeId, load, { immediate: true })

onUnmounted(() => {
  if (cart.count === 0) cart.stopHold()
})

const selectedSeats = computed(() => cart.seats.filter((s) => cart.selectedIds.has(s.id)))

const sessionsSorted = computed(() =>
  [...props.sessions].sort((a, b) => String(startsAtOf(a)).localeCompare(String(startsAtOf(b)))),
)

/**
 * Подпись над схемой: площадка, зал, дата и время.
 *
 * «Основной зал» сам по себе ничего не говорит — он есть у каждой площадки,
 * поэтому зал идёт вслед за её названием, а не вместо него.
 */
const schemaCaption = computed(() => {
  const s = activeSession.value
  const parts: string[] = []
  if (props.venueName) parts.push(props.venueName)
  if (s) parts.push(hallOf(s))
  if (s && startsAtOf(s)) parts.push(`${dateFull(startsAtOf(s))}, ${time(startsAtOf(s))}`)
  return parts.filter(Boolean).join(' · ')
})

const soldOut = computed(() => {
  const s = activeSession.value
  return !s || availableOf(s) === 0
})

/* ── Вход в кабинет прямо в рамке ───────────────────────────────────────────
 * Пароль живёт отдельным ref'ом и не попадает в `usePurchaseFlow`: это вход,
 * а не оплата, и смешивать их значило бы тащить пароль через общую форму.
 */
const loginEmail = ref('')
const loginPassword = ref('')
const loggingIn = ref(false)
const loginError = ref('')
/**
 * E-mail вошедшего покупателя.
 *
 * Именно `ref`, а не `computed(() => customerUser()?.email)`: computed без
 * реактивных зависимостей кешируется навсегда, и после входа в кабинет шапка
 * показывала бы прежнее (пустое) значение — профиль приходит из localStorage,
 * который Vue не отслеживает.
 */
const profileEmail = ref(customerUser()?.email ?? '')

/**
 * Вход в кабинет.
 *
 * Свой e-mail, а НЕ общий с контактами: в форме было бы два поля «E-mail» —
 * одно для входа, другое для билета, — и покупатель не понимал, какое из них
 * куда уйдёт. После входа адрес профиля подставляется в контакты сам
 * (`prefillFromUser`).
 */
async function submitLogin(): Promise<void> {
  if (loggingIn.value) return
  loggingIn.value = true
  loginError.value = ''
  const user = await purchase.login(loginEmail.value, loginPassword.value)
  if (user) {
    loginPassword.value = ''
    profileEmail.value = user.email
  } else {
    loginError.value = 'Не удалось войти. Проверьте e-mail и пароль.'
  }
  loggingIn.value = false
}

async function signOut(): Promise<void> {
  await logoutCustomer()
  loggedIn.value = false
  profileEmail.value = ''
}
</script>

<template>
  <section class="min-w-0" aria-label="Покупка билетов">
    <!-- ── Сеансы ──────────────────────────────────────────────────────────
         Крупные кнопки с датой, временем и остатком: «куда я иду и во сколько»
         должно считываться за секунду. Недоступный сеанс не прячем — серым,
         чтобы было видно, что он есть. -->
    <!-- `data-sessions` — якорь для тестов: у кнопок «В избранное»/«Следить»
         тот же `aria-pressed`, и без контейнера их не отличить от сеансов. -->
    <div v-if="sessionsSorted.length > 1" data-sessions class="flex flex-wrap gap-2">
      <button
        v-for="session in sessionsSorted"
        :key="session.id"
        type="button"
        :disabled="availableOf(session) === 0"
        :aria-pressed="session.id === activeId"
        :class="[
          'rounded-lg border px-3 py-2 text-left text-sm transition-colors',
          session.id === activeId
            ? 'border-brand-500 bg-brand-500/10 text-content'
            : 'border-line bg-surface-2 text-muted hover:border-brand-500/40 hover:text-content',
          availableOf(session) === 0 ? 'cursor-not-allowed opacity-45' : '',
        ]"
        @click="switchTo(session.id)"
      >
        <span class="block font-medium">{{ dateFull(startsAtOf(session)) }}</span>
        <span class="mt-0.5 block text-xs">
          {{ time(startsAtOf(session)) }} ·
          {{ availableOf(session) === 0 ? 'нет мест' : seatsLabel(availableOf(session)) }}
        </span>
      </button>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_340px]">
      <!-- ── Схема зала ────────────────────────────────────────────────────
           Настоящая схема: каждая точка — реальное место из `inventory_items`,
           поставленное по своим координатам. Клик по месту = ВЫБОР, а не
           «добавить в корзину»: холд ставится тут же, но покупатель остаётся
           на этом же экране. -->
      <div class="min-w-0">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold text-content">Выберите места</h2>
          <p class="text-xs text-subtle">
            {{ schemaCaption }}
            <template v-if="!loading && totalSeats"> · всего {{ totalSeats }}</template>
          </p>
        </div>

        <p v-if="truncated && !loading && !loadError" class="mt-2 text-xs text-sun-500" role="alert">
          Показаны не все места: загружено {{ inventory.length }} из {{ totalSeats }}. Выберите другой сеанс или обновите страницу.
        </p>

        <div v-if="loading" class="surface-card mt-3 px-4 py-10 text-center text-sm text-subtle">
          Загружаем схему зала…
        </div>

        <div v-else-if="loadError" class="surface-card mt-3 flex flex-col items-center gap-3 px-4 py-10 text-center">
          <p class="text-sm text-danger-500">Не удалось загрузить места: {{ loadError }}</p>
          <NButton variant="secondary" size="sm" @click="load">Повторить</NButton>
        </div>

        <div v-else-if="inventory.length === 0" class="surface-card mt-3 px-4 py-6 text-center text-sm text-subtle">
          Для этого сеанса пока нет опубликованной схемы зала.
        </div>

        <div v-else class="mt-3">
          <CoordSeatMap
            v-if="useCoordMap"
            :inventory="inventory"
            :selected="[...cart.selectedIds]"
            :selected-count="cart.ticketsCount"
            :max-quantity="maxTickets"
            :stage-side="stageSide"
            @toggle="onToggleCoord"
            @limit="onLimit"
          />

          <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-2xs text-subtle">Нажмите на место, чтобы выбрать его</p>
            <p v-if="cart.count > 0" class="text-2xs text-subtle">
              выбрано {{ ticketsLabel(cart.ticketsCount) }} · {{ money(cart.totalMinor) }}
            </p>
          </div>
        </div>

        <p v-if="cart.holdExtendError" class="mt-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400" role="alert">
          {{ cart.holdExtendError }}
        </p>
      </div>

      <!-- ── Правая колонка: итог → контакты → готово ───────────────────────
           Один и тот же столбец на все шаги: покупатель не меняет экран, а
           меняется содержимое панели рядом со схемой. -->
      <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
        <!-- Шаг 1. Выбор -->
        <OrderSummary
          v-if="step === 'seats'"
          :seats="selectedSeats"
          :subtotal-minor="cart.subtotalMinor"
          :discount-minor="cart.discountMinor"
          :hold-seconds-left="cart.holdSecondsLeft"
          cta-label="Далее"
          :disabled="cart.count === 0 || soldOut"
          @remove="remove"
          @extend="cart.extendHold()"
          @clear="cart.clear()"
          @submit="purchase.openContacts()"
        />

        <!-- Шаг 2. Контакты и оплата — прямо в этой же рамке -->
        <div v-else-if="step === 'contacts'" class="surface-card p-4">
          <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-content">Оформление</h2>
            <button
              type="button"
              class="flex items-center gap-1 text-xs text-subtle transition-colors hover:text-content"
              @click="purchase.backToSeats()"
            >
              <span aria-hidden="true">←</span> Изменить места
            </button>
          </div>

          <NCountdown
            v-if="cart.holdSecondsLeft > 0"
            :seconds-left="cart.holdSecondsLeft"
            :total="900"
            class="mt-3"
            @extend="cart.extendHold()"
          />

          <ul class="mt-3 space-y-1.5">
            <li
              v-for="seat in selectedSeats"
              :key="seat.id"
              class="flex items-center justify-between gap-2 text-sm"
            >
              <span class="truncate text-muted">{{ seatLineLabel(seat) }}</span>
              <span class="flex-none tabular-nums text-content">{{ money(seatLineTotal(seat)) }}</span>
            </li>
          </ul>

          <dl class="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
            <div class="flex justify-between">
              <dt class="text-muted">{{ ticketsLabel(cart.ticketsCount) }} · {{ seatsLabel(selectedSeats.length) }}</dt>
              <dd class="tabular-nums text-content">{{ money(cart.subtotalMinor) }}</dd>
            </div>
            <div v-if="bonusMinor > 0" class="flex justify-between">
              <dt class="text-mint-400">Бонусами</dt>
              <dd class="tabular-nums text-mint-400">−{{ money(bonusMinor) }}</dd>
            </div>
            <div class="flex items-baseline justify-between border-t border-line pt-2">
              <dt class="font-medium text-content">Итого</dt>
              <dd class="text-xl font-semibold tabular-nums text-content">{{ money(totalMinor) }}</dd>
            </div>
          </dl>

          <!-- Вход в кабинет. Он НЕ обязателен для покупки: аккаунт заводится
               сам при оплате. Он нужен, чтобы билеты сохранились в кабинете и
               стали доступны бонусы, — поэтому стоит выше контактов, но не
               преграждает путь. -->
          <div class="mt-4 border-t border-line pt-4">
            <template v-if="loggedIn">
              <p class="text-xs text-muted">
                Вы вошли как <span class="font-medium text-content">{{ profileEmail }}</span>
                <button type="button" class="ml-1.5 text-subtle underline hover:text-content" @click="signOut">Выйти</button>
              </p>
            </template>
            <template v-else>
              <p class="text-xs font-medium text-content">Есть аккаунт? Войдите</p>
              <div class="mt-2 space-y-2">
                <NInput
                  v-model="loginEmail"
                  type="email"
                  label="E-mail кабинета"
                  placeholder="ivan@example.com"
                  autocomplete="email"
                  inputmode="email"
                  required
                />
                <NInput
                  v-model="loginPassword"
                  type="password"
                  label="Пароль"
                  placeholder="••••••••"
                  autocomplete="current-password"
                  required
                />
                <NButton
                  variant="secondary"
                  size="sm"
                  block
                  :loading="loggingIn"
                  @click="submitLogin"
                >
                  Войти
                </NButton>
                <p v-if="loginError" class="text-xs text-rose-400" role="alert">{{ loginError }}</p>
              </div>
              <p class="mt-2 text-2xs text-subtle">
                Билет придёт на e-mail даже без аккаунта — аккаунт нужен только чтобы не потерять его при смене браузера.
              </p>
            </template>
          </div>

          <!-- Контакты -->
          <div class="mt-4 space-y-3 border-t border-line pt-4">
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
              type="email"
              label="E-mail"
              placeholder="ivan@example.com"
              inputmode="email"
              autocomplete="email"
              required
              :error="submitted ? errors.email : ''"
            />
            <NInput
              v-model="emailConfirm"
              type="email"
              label="E-mail ещё раз"
              placeholder="ivan@example.com"
              inputmode="email"
              autocomplete="email"
              required
              hint="На него уйдёт билет — опечатка задержит доставку"
              :error="submitted ? errors.emailConfirm : ''"
            />

            <NCheckbox
              v-model="agree"
              label="Согласен с офертой и правилами посещения"
              description="Возврат возможен не позднее чем за 24 часа до начала сеанса"
            />
            <p v-if="submitted && errors.agree" class="text-xs text-rose-400">
              {{ errors.agree }}
            </p>

            <NCheckbox
              v-if="canUseBonuses"
              v-model="useBonuses"
              :label="bonusLabel"
              :description="bonusHint"
            />

            <p
              v-if="emailOk"
              class="rounded-lg border border-line bg-surface-2 px-3 py-2 text-xs text-muted"
            >
              Билет придёт на <span class="font-medium text-content">{{ email.trim() }}</span>
            </p>

            <NButton
              variant="accent"
              size="lg"
              block
              :loading="paying"
              @click="purchase.pay"
            >
              Оплатить {{ money(totalMinor) }}
            </NButton>

            <p v-if="submitted && !canPay" class="text-center text-xs text-sun-400">
              {{ missingHint }}
            </p>
            <p class="text-center text-2xs text-subtle">
              Оплата проходит через ЮKassa. Статус заказа подтверждает сервер, а не редирект.
            </p>
          </div>
        </div>

        <!-- Шаг 3. Готово -->
        <div v-else class="surface-card p-5 text-center">
          <p class="text-2xl" aria-hidden="true">✓</p>
          <h2 class="mt-2 text-base font-semibold text-content">Оплата прошла</h2>
          <p class="mt-1 text-sm text-muted">
            Заказ №{{ outcome?.orderId }} на {{ money(outcome?.totalMinor ?? 0) }} оплачен.
            Билеты отправлены на {{ outcome?.email }}.
          </p>
          <div class="mt-4 flex flex-col gap-2">
            <RouterLink
              to="/tickets"
              class="block w-full rounded-lg bg-accent-gradient px-4 py-3 text-center text-base font-semibold text-white shadow-accent transition hover:brightness-110"
            >
              Мои билеты
            </RouterLink>
            <NButton variant="secondary" block @click="purchase.reset()">
              Купить ещё
            </NButton>
          </div>
        </div>
      </aside>
    </div>
  </section>
</template>
