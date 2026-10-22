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
 *
 * РАСКЛАДКА: СХЕМА ВО ВСЮ ШИРИНУ
 *   Было две колонки — схема и сводка 340 px рядом. На витрине виджет и так
 *   стоит в правой колонке карточки события, поэтому схема получала треть
 *   трети: точки мест мельче ногтя, выбор только через зум. Теперь на шаге
 *   выбора колонки нет вовсе: схема занимает всю ширину, а сводка живёт
 *   нижней полосой ВНУТРИ её рамки — места чипами, сумма, «Далее». Колонка
 *   возвращается на шаге контактов, где она не мешает: пока человек заполняет
 *   форму, ему полезно видеть выбранные места и таймер удержания.
 */
import { computed, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NCountdown from '@/components/ui/NCountdown.vue'
import CoordSeatMap from '@/components/seat/CoordSeatMap.vue'
import SeatMap from '@/components/seat/SeatMap.vue'
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

const props = defineProps<{
  /** Сеансы события: из них покупатель выбирает дату и время. */
  sessions: WidgetSession[]
  /** Активный сеанс (v-model). */
  sessionId: string
}>()

const emit = defineEmits<{ 'update:sessionId': [id: string] }>()

const cart = useCartStore()
const loyalty = useLoyaltyStore()
const ui = useUiStore()

/* Активный сеанс — свой ref: переключение идёт через снятие холдов. */
const activeId = ref(props.sessionId)

const startsAtOf = (s: WidgetSession): string => s.starts_at ?? s.startsAt ?? ''
const availableOf = (s: WidgetSession): number => s.available_seats ?? s.availableSeats ?? 0

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
  hall,
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

const { hydrateCart, onToggle, onToggleCoord, onLimit, remove } = useSeatHolds({
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
 * «Всё распродано» — по счётчику сеанса, а не по загруженным местам: пагинация
 * могла не дотянуть часть инвентаря, и по ней вывод о распроданности был бы
 * неверным.
 */
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

    <!--
      ── Одна рамка: схема зала, а итог — ВНУТРИ неё, нижней полосой ──

      Раньше рядом со схемой стояла отдельная колонка 340 px, и карта получала
      треть ширины: точки мест были мельче ногтя, а попасть в нужное место
      можно было только зумом. Теперь схема занимает всю ширину фрейма, а
      «Ваш выбор» — полоса под ней: места чипами, сумма и «Далее». Колонка
      остаётся только у формы контактов, где она уместна и не отнимает место
      у схемы.

      Подписи «Театр СурГУ · Основной зал · вт, 20 октября, 21:00 · всего 512»
      здесь больше нет: дата и площадка уже в шапке события, а число мест —
      в шапке самой схемы. Три раза одно и то же — это не подсказка, а шум.
    -->
    <!-- Шаг 3. Готово. Схема здесь больше не нужна — билеты уже проданы. -->
    <div v-if="step === 'done'" class="surface-card mx-auto mt-4 max-w-md p-5 text-center">
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

    <div
      v-else
      class="mt-4"
      :class="step === 'contacts' ? 'grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_380px]' : ''"
    >
      <!-- ── Схема зала ────────────────────────────────────────────────────
           Настоящая схема: каждая точка — реальное место из `inventory_items`,
           поставленное по своим координатам. Клик по месту = ВЫБОР, а не
           «добавить в корзину»: холд ставится тут же, но покупатель остаётся
           на этом же экране. -->
      <div class="min-w-0">
        <p v-if="truncated && !loading && !loadError" class="mb-2 text-xs text-sun-500" role="alert">
          Показаны не все места: загружено {{ inventory.length }} из {{ totalSeats }}. Выберите другой сеанс или обновите страницу.
        </p>

        <div v-if="loading" class="surface-card px-4 py-10 text-center text-sm text-subtle">
          Загружаем схему зала…
        </div>

        <div v-else-if="loadError" class="surface-card flex flex-col items-center gap-3 px-4 py-10 text-center">
          <p class="text-sm text-danger-500">Не удалось загрузить места: {{ loadError }}</p>
          <NButton variant="secondary" size="sm" @click="load">Повторить</NButton>
        </div>

        <div v-else-if="inventory.length === 0" class="surface-card px-4 py-6 text-center text-sm text-subtle">
          Для этого сеанса пока нет опубликованной схемы зала.
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-line bg-surface">
          <CoordSeatMap
            v-if="useCoordMap"
            class="!rounded-none !border-0"
            :inventory="inventory"
            :selected="[...cart.selectedIds]"
            :selected-count="cart.ticketsCount"
            :max-quantity="maxTickets"
            :stage-side="stageSide"
            @toggle="onToggleCoord"
            @limit="onLimit"
          />
          <!--
            Зал без координат — рядная карта. Раньше на этом месте стоял
            пустой `v-else`: схема не рисовалась вообще, и билет было купить
            нечем (ни одного места на экране).
          -->
          <SeatMap
            v-else
            class="!rounded-none !border-0"
            :selected="[...cart.selectedIds]"
            :max-selection="maxTickets"
            :sectors="hall"
            @toggle="onToggle"
            @limit="onLimit"
          />

          <!-- Шаг 1. Выбор: итог живёт в рамке схемы, а не в отдельной колонке. -->
          <div v-if="step === 'seats'" data-summary class="border-t border-line px-3 py-3 sm:px-4">
            <NCountdown
              v-if="cart.holdSecondsLeft > 0"
              :seconds-left="cart.holdSecondsLeft"
              :total="900"
              class="mb-2.5 max-w-sm"
              @extend="cart.extendHold()"
            />

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5">
              <div class="min-w-0 flex-1">
                <p v-if="selectedSeats.length === 0" class="text-sm text-subtle">
                  Нажмите на место на схеме — оно появится здесь
                </p>
                <ul v-else class="flex flex-wrap items-center gap-1.5">
                  <li
                    v-for="seat in selectedSeats"
                    :key="seat.id"
                    class="flex items-center gap-2 rounded-lg border border-line bg-surface-2 py-1 pl-2.5 pr-1 text-xs"
                  >
                    <span class="max-w-[14rem] truncate text-content">{{ seatLineLabel(seat) }}</span>
                    <span class="flex-none tabular-nums text-muted">{{ money(seatLineTotal(seat)) }}</span>
                    <button
                      type="button"
                      class="grid size-5 flex-none place-items-center rounded text-subtle transition-colors hover:text-rose-400"
                      :aria-label="`Убрать: ${seatLineLabel(seat)}`"
                      @click="remove(seat.id)"
                    >✕</button>
                  </li>
                  <li v-if="cart.discountMinor > 0" class="text-2xs text-mint-400">
                    −{{ money(cart.discountMinor) }} по промокоду
                  </li>
                  <li>
                    <button
                      type="button"
                      class="px-1 text-2xs text-subtle underline transition-colors hover:text-content"
                      @click="cart.clear()"
                    >Очистить</button>
                  </li>
                </ul>
              </div>

              <div class="ml-auto flex flex-none items-center gap-3">
                <!-- Пока ничего не выбрано, «0 билетов · 0 ₽» — шум: места ещё
                     нет, и сумма ничего не значит. -->
                <div v-if="cart.count > 0" class="text-right leading-tight">
                  <p class="text-2xs text-subtle">{{ ticketsLabel(cart.ticketsCount) }}</p>
                  <p class="text-lg font-semibold tabular-nums text-content">{{ money(cart.totalMinor) }}</p>
                </div>
                <NButton
                  variant="accent"
                  size="lg"
                  :disabled="cart.count === 0 || soldOut"
                  @click="purchase.openContacts()"
                >
                  Далее
                </NButton>
              </div>
            </div>
          </div>
        </div>

        <p v-if="cart.holdExtendError" class="mt-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400" role="alert">
          {{ cart.holdExtendError }}
        </p>
      </div>

      <!-- ── Шаг 2. Контакты и оплата ───────────────────────────────────────
           Колонка рядом со схемой появляется ТОЛЬКО здесь: заполняя форму,
           полезно видеть выбранные места и время удержания. На шаге выбора
           этой колонки нет — она отнимала у схемы треть ширины. -->
      <aside v-if="step === 'contacts'" class="min-w-0">
        <div class="surface-card p-4">
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
      </aside>
    </div>
  </section>
</template>
