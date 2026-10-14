<script setup lang="ts">
/**
 * Личный кабинет покупателя.
 *
 * ЧТО ЗДЕСЬ И ПОЧЕМУ ИМЕННО ЭТО
 *   Четыре вещи, которые покупатель не может сделать больше нигде, и каждая —
 *   следствие того, что аккаунт заводится автоматически при покупке
 *   (`CustomerAccountService::resolveForCheckout()`), а не по доброй воле:
 *
 *     1. ПРОФИЛЬ — потому что имя и адрес на заказе он вводил в спешке, а
 *        опечатка в адресе означает, что билет не придёт (`/me`, `PATCH /me`).
 *     2. ПАРОЛЬ — потому что у автоматически созданного аккаунта его нет вовсе,
 *        и «сменить» здесь означает «задать впервые» (`POST /me/password`).
 *     3. ЗАКАЗЫ — потому что «мои билеты» отвечали по гостевому токену браузера,
 *        и смена устройства означала потерю билетов навсегда (`/me/orders`).
 *     4. СЕССИИ — потому что «выйти везде» иначе недоступно, а пароль меняют в
 *        том числе тогда, когда подозревают чужой вход (`/me/sessions`).
 *     5. КЛУБ ЗРИТЕЛЕЙ — бонусный счёт и ссылки на «Избранное» и «Подписки»:
 *        сами списки и история бонусов живут на своих маршрутах (`/bonus`,
 *        `/favorites`, `/subscriptions`), но найти их покупатель должен из
 *        кабинета. Баланс в плитке — из `stores/loyalty.ts`, того же
 *        состояния, что и чип в шапке.
 *
 * СМЕНА ПАРОЛЯ ОТЗЫВАЕТ ВСЕ СЕССИИ, ВКЛЮЧАЯ ЭТУ. Это правило проекта, а не
 * побочный эффект (`UserService::updateUser()`): пароль меняют, подозревая чужой
 * доступ, и оставить чужую сессию живой — значит закрыть дверь, не выгнав того,
 * кто внутри. Поэтому после успеха экран честно говорит «войдите заново», а не
 * делает вид, что ничего не произошло.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import {
  changePassword,
  clearCustomerSession,
  customerUser,
  fetchMe,
  fetchMyOrders,
  fetchSessions,
  logoutCustomer,
  revokeSession,
  updateMe,
  type CustomerOrder,
  type CustomerSession,
  type CustomerUser,
} from '@/lib/customer'
import { ApiError } from '@/lib/api'
import { dateTime, money, plural } from '@/lib/format'
import { useLoyaltyStore } from '@/stores/loyalty'

const router = useRouter()

const me = ref<CustomerUser | null>(customerUser())
const loading = ref(true)
const loadError = ref<string | null>(null)
const loyalty = useLoyaltyStore()

const displayName = computed(() => {
  const name = [me.value?.first_name, me.value?.last_name].filter(Boolean).join(' ').trim()
  return name === '' ? me.value?.email ?? 'Покупатель' : name
})

const initials = computed(() => {
  const first = me.value?.first_name?.trim()?.[0] ?? ''
  const last = me.value?.last_name?.trim()?.[0] ?? ''
  return (first + last).toUpperCase() || (me.value?.email?.[0] ?? '?').toUpperCase()
})

/* ── Профиль ───────────────────────────────────────────────────────────── */

const profile = ref({ first_name: '', last_name: '', email: '', phone: '' })
const profileSaving = ref(false)
const profileError = ref<string | null>(null)
const profileFields = ref<Record<string, string>>({})
const profileSaved = ref(false)

function fillProfile(user: CustomerUser | null): void {
  profile.value = {
    first_name: user?.first_name ?? '',
    last_name: user?.last_name ?? '',
    email: user?.email ?? '',
    phone: user?.phone ?? '',
  }
}

async function saveProfile(): Promise<void> {
  if (profileSaving.value) return
  profileSaving.value = true
  profileError.value = null
  profileFields.value = {}
  profileSaved.value = false

  try {
    const updated = await updateMe({ ...profile.value })
    me.value = updated
    fillProfile(updated)
    profileSaved.value = true
  } catch (e) {
    if (e instanceof ApiError && e.details) {
      const flat: Record<string, string> = {}
      for (const [field, messages] of Object.entries(e.details)) {
        if (Array.isArray(messages) && messages.length > 0) flat[field] = String(messages[0])
      }
      profileFields.value = flat
    }
    // `EMAIL_CHANGE_REJECTED` — адрес уже подтверждён, и правка профиля его не
    // переносит. Сообщение сервера объясняет это лучше, чем общая строка.
    if (Object.keys(profileFields.value).length === 0) {
      profileError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить профиль.'
    }
  } finally {
    profileSaving.value = false
  }
}

/* ── Пароль ────────────────────────────────────────────────────────────── */

const currentPassword = ref('')
const newPassword = ref('')
const newPasswordAgain = ref('')
const passwordSaving = ref(false)
const passwordError = ref<string | null>(null)
const passwordDone = ref(false)

async function savePassword(): Promise<void> {
  if (passwordSaving.value) return
  passwordError.value = null

  if (newPassword.value !== newPasswordAgain.value) {
    passwordError.value = 'Новые пароли не совпадают.'
    return
  }

  passwordSaving.value = true
  try {
    await changePassword(newPassword.value, currentPassword.value || undefined)
    // Сессии уже отозваны сервером — локальную тоже гасим, иначе витрина
    // считала бы покупателя вошедшим с мёртвым токеном.
    clearCustomerSession()
    passwordDone.value = true
  } catch (e) {
    if (e instanceof ApiError && e.details?.current_password) {
      passwordError.value = String(e.details.current_password[0])
    } else if (e instanceof ApiError && e.details?.password) {
      passwordError.value = String(e.details.password[0])
    } else if (e instanceof ApiError && e.status === 429) {
      passwordError.value = 'Слишком много попыток. Подождите минуту и повторите.'
    } else {
      passwordError.value = e instanceof ApiError ? e.message : 'Не удалось изменить пароль.'
    }
  } finally {
    passwordSaving.value = false
  }
}

/* ── Заказы ────────────────────────────────────────────────────────────── */

const orders = ref<CustomerOrder[]>([])
const ordersLoading = ref(false)

/* ── Сессии ────────────────────────────────────────────────────────────── */

const sessions = ref<CustomerSession[]>([])
const sessionsLoading = ref(false)
const revokingId = ref<string | null>(null)

/* ── Загрузка ──────────────────────────────────────────────────────────── */

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const user = await fetchMe()
    me.value = user
    fillProfile(user)
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить профиль.'
  } finally {
    loading.value = false
  }

  void loadOrders()
  void loadSessions()
  // Счётчики «♡»/«🔔» для раздела «Клуб зрителей». Вызов идемпотентен:
  // оболочка витрины грузит списки при навигации, повторный запрос не уйдёт.
  void loyalty.load()
}

async function loadOrders(): Promise<void> {
  ordersLoading.value = true
  try {
    orders.value = await fetchMyOrders()
  } catch {
    orders.value = []
  } finally {
    ordersLoading.value = false
  }
}

async function loadSessions(): Promise<void> {
  sessionsLoading.value = true
  try {
    sessions.value = await fetchSessions()
  } catch {
    sessions.value = []
  } finally {
    sessionsLoading.value = false
  }
}

async function endSession(id: string): Promise<void> {
  revokingId.value = id
  try {
    await revokeSession(id)
    await loadSessions()
  } catch {
    /* список перечитаем в любом случае — состояние важнее текста ошибки */
    await loadSessions()
  } finally {
    revokingId.value = null
  }
}

async function signOut(): Promise<void> {
  await logoutCustomer()
  router.push('/')
}

/** Понятная подпись устройства: `user_agent` длинный, `device_name` часто пуст. */
function deviceLabel(session: CustomerSession): string {
  if (session.device_name) return session.device_name
  const ua = session.user_agent ?? ''
  if (ua === '') return 'Неизвестное устройство'
  if (/mobile|android|iphone/i.test(ua)) return 'Мобильное устройство'
  if (/curl|wget|httpie/i.test(ua)) return 'Программный клиент'
  return 'Браузер'
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <!-- Смена пароля отозвала сессии: показываем это вместо экрана, действия
         на котором всё равно упрутся в 401. -->
    <template v-if="passwordDone">
      <div class="surface-card mx-auto mt-6 max-w-md p-6">
        <h1 class="font-display text-xl font-bold tracking-tight text-content">Пароль изменён</h1>
        <p class="mt-3 text-sm text-muted">
          Все активные сессии завершены — это часть смены пароля. Войдите заново с новым паролем.
        </p>
        <NButton variant="primary" block class="mt-4" @click="router.push('/login')">Войти</NButton>
      </div>
    </template>

    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
          <span
            class="grid h-12 w-12 flex-none place-items-center rounded-xl bg-brand-gradient text-lg font-bold text-brand-on shadow-brand"
            aria-hidden="true"
          >{{ initials }}</span>
          <div class="min-w-0">
            <h1 class="truncate font-display text-2xl font-bold tracking-tight text-content">{{ displayName }}</h1>
            <p class="truncate text-sm text-muted">{{ me?.email }}</p>
          </div>
        </div>
        <NButton variant="secondary" @click="signOut">Выйти</NButton>
      </div>

      <p v-if="loading" class="mt-6 text-sm text-subtle">Загружаем кабинет…</p>
      <p v-else-if="loadError" class="mt-6 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400">
        {{ loadError }}
      </p>

      <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <!-- Профиль -->
        <section class="surface-card p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-subtle">Данные профиля</h2>
          <p class="mt-1.5 text-xs text-subtle">
            Имя и адрес попадают в билеты. Опечатка в адресе — самая частая причина,
            по которой письмо не приходит.
          </p>

          <form class="mt-4 space-y-4" @submit.prevent="saveProfile">
            <div class="grid gap-4 sm:grid-cols-2">
              <NInput v-model="profile.first_name" label="Имя" autocomplete="given-name" :error="profileFields.first_name" />
              <NInput v-model="profile.last_name" label="Фамилия" autocomplete="family-name" :error="profileFields.last_name" />
            </div>

            <NInput
              v-model="profile.email"
              type="email"
              label="E-mail"
              autocomplete="email"
              inputmode="email"
              :error="profileFields.email"
              hint="На него приходят билеты"
            />

            <NInput
              v-model="profile.phone"
              type="tel"
              label="Телефон"
              autocomplete="tel"
              inputmode="tel"
              :error="profileFields.phone"
            />

            <p v-if="profileError" class="text-xs text-rose-400">{{ profileError }}</p>
            <p v-else-if="profileSaved" class="text-xs text-mint-400">Профиль сохранён.</p>

            <NButton type="submit" variant="primary" :loading="profileSaving">Сохранить</NButton>
          </form>
        </section>

        <!-- Пароль -->
        <section class="surface-card p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-subtle">Пароль</h2>
          <p class="mt-1.5 text-xs text-subtle">
            Аккаунт, заведённый при покупке, создаётся без пароля — здесь вы задаёте его впервые.
            Если пароль уже был, укажите текущий.
          </p>

          <form class="mt-4 space-y-4" @submit.prevent="savePassword">
            <NInput
              v-model="currentPassword"
              type="password"
              label="Текущий пароль"
              autocomplete="current-password"
              hint="Оставьте пустым, если пароль ещё не задавали"
            />

            <NInput
              v-model="newPassword"
              type="password"
              label="Новый пароль"
              autocomplete="new-password"
              hint="Минимум 12 символов: заглавные и строчные буквы, цифра и знак"
            />

            <NInput
              v-model="newPasswordAgain"
              type="password"
              label="Новый пароль ещё раз"
              autocomplete="new-password"
            />

            <p v-if="passwordError" class="text-xs text-rose-400">{{ passwordError }}</p>

            <NButton type="submit" variant="primary" :loading="passwordSaving">Сменить пароль</NButton>
          </form>
        </section>

        <!-- Заказы -->
        <section class="surface-card p-5">
          <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-subtle">Заказы</h2>
            <NButton variant="ghost" size="sm" @click="loadOrders">Обновить</NButton>
          </div>

          <p v-if="ordersLoading" class="mt-4 text-sm text-subtle">Загружаем заказы…</p>

          <NEmptyState
            v-else-if="orders.length === 0"
            class="mt-4"
            icon="◫"
            title="Заказов пока нет"
            description="Здесь появятся покупки, оформленные на этот аккаунт."
          />

          <ul v-else class="mt-4 divide-y divide-line">
            <li v-for="order in orders" :key="order.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-content">
                  {{ order.eventTitles[0] || `Заказ ${order.number}` }}
                </p>
                <p class="mt-0.5 text-xs text-subtle">
                  {{ order.number || order.id }} · {{ dateTime(order.createdAt) }}
                  <template v-if="order.itemsCount > 0">
                    · {{ order.itemsCount }} {{ plural(order.itemsCount, 'позиция', 'позиции', 'позиций') }}
                  </template>
                </p>
              </div>
              <div class="flex flex-none items-center gap-3">
                <span class="text-sm font-semibold tabular-nums text-content">{{ money(order.totalMinor, { currency: order.currency }) }}</span>
                <NStatusBadge kind="order" :status="order.status" />
              </div>
            </li>
          </ul>

          <NButton v-if="orders.length" variant="secondary" class="mt-4" @click="router.push('/tickets')">
            Мои билеты
          </NButton>
        </section>

        <!-- Клуб зрителей: бонусный счёт, избранное и подписки -->
        <section class="surface-card p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-subtle">Клуб зрителей</h2>
          <p class="mt-1.5 text-xs text-subtle">
            Бонусы за покупки, отложенные мероприятия и подписки на письма о новых сеансах.
          </p>

          <!--
            Бонусный счёт — во всю ширину, а не третьей плиткой: у него есть
            число, которое хочется прочитать, и переход «за подробностями».
            Плитка в общей сетке уравняла бы «250 бонусов» с подписью
            «0 мероприятий», и баланс перестал бы быть заметным.
          -->
          <RouterLink
            to="/bonus"
            class="mt-4 flex items-center gap-3 rounded-lg border border-line p-3 transition-colors hover:border-brand-500/40 hover:bg-surface-2"
          >
            <span class="grid h-10 w-10 flex-none place-items-center rounded-lg bg-surface-3 text-lg text-brand-400" aria-hidden="true">✦</span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-medium text-content">Бонусный счёт</span>
              <span class="block text-xs text-subtle">
                {{ loyalty.bonusesCount }} {{ plural(loyalty.bonusesCount, 'бонус', 'бонуса', 'бонусов') }} · кэшбэк 5 % с оплаченных заказов
              </span>
            </span>
            <span class="flex-none text-xs text-brand-400">История →</span>
          </RouterLink>

          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <RouterLink
              to="/favorites"
              class="flex items-center gap-3 rounded-lg border border-line p-3 transition-colors hover:border-brand-500/40 hover:bg-surface-2"
            >
              <span class="grid h-10 w-10 flex-none place-items-center rounded-lg bg-surface-3 text-lg text-rose-400" aria-hidden="true">♡</span>
              <span class="min-w-0">
                <span class="block text-sm font-medium text-content">Избранное</span>
                <span class="block text-xs text-subtle">
                  {{ loyalty.favoritesCount }} {{ plural(loyalty.favoritesCount, 'мероприятие', 'мероприятия', 'мероприятий') }}
                </span>
              </span>
            </RouterLink>

            <RouterLink
              to="/subscriptions"
              class="flex items-center gap-3 rounded-lg border border-line p-3 transition-colors hover:border-brand-500/40 hover:bg-surface-2"
            >
              <span class="grid h-10 w-10 flex-none place-items-center rounded-lg bg-surface-3 text-lg" aria-hidden="true">🔔</span>
              <span class="min-w-0">
                <span class="block text-sm font-medium text-content">Подписки</span>
                <span class="block text-xs text-subtle">
                  {{ loyalty.followsCount }} {{ plural(loyalty.followsCount, 'мероприятие', 'мероприятия', 'мероприятий') }}
                </span>
              </span>
            </RouterLink>
          </div>
        </section>

        <!-- Сессии -->
        <section class="surface-card p-5">
          <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-subtle">Активные сессии</h2>
            <NButton variant="ghost" size="sm" @click="loadSessions">Обновить</NButton>
          </div>

          <p class="mt-1.5 text-xs text-subtle">
            Если видите незнакомое устройство — завершите сессию и смените пароль.
          </p>

          <p v-if="sessionsLoading" class="mt-4 text-sm text-subtle">Загружаем сессии…</p>

          <p v-else-if="sessions.length === 0" class="mt-4 text-sm text-subtle">Активных сессий нет.</p>

          <ul v-else class="mt-4 divide-y divide-line">
            <li v-for="session in sessions" :key="session.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
              <div class="min-w-0">
                <p class="truncate text-sm text-content">{{ deviceLabel(session) }}</p>
                <p class="mt-0.5 text-xs text-subtle">Начата {{ dateTime(session.created_at ?? '') }}</p>
              </div>
              <NButton
                variant="ghost"
                size="sm"
                :loading="revokingId === session.id"
                @click="endSession(session.id)"
              >Завершить</NButton>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>
