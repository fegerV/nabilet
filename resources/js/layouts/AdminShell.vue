<script setup lang="ts">
/**
 * Оболочка админки организатора.
 *
 * Отличается от витрины по двум причинам. Первая: здесь работают часами,
 * поэтому плотность выше, а анимации тише. Вторая: администратор переключает
 * контекст (организацию, мероприятие) чаще, чем листает разделы, поэтому
 * переключатель организации стоит на самом видном месте — у мультитенантной
 * системы ошибка «сделал не в той компании» стоит дороже любого другого.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUiStore } from '@/stores/ui'
import { logout, clearSession, currentUser } from '@/lib/auth'
import { usePersistedRef } from '@/lib/usePersistedRef'
import { get } from '@/lib/api'

const ui = useUiStore()
const router = useRouter()
/** Свёрнутость меню переживает F5: человек, свернувший его ради места, не должен
 *  разворачивать его заново на каждой перезагрузке (см. usePersistedRef). */
const collapsed = usePersistedRef('admin.sidebar.collapsed', false)

async function doLogout(): Promise<void> {
  await logout()
  // D2: clearSession стирает и токен, и пользователя — расхождение между
  // этими двумя ключами и позволяло «полутоку» остаться в localStorage.
  clearSession()
  router.push('/admin/login')
}

/**
 * Пункт меню. `badge` опционален: его подставляет `groups` из реальных данных,
 * а не литерал в объявлении (см. ниже).
 */
interface NavItem {
  label: string
  to: string
  icon: string
  badge?: string
}

interface NavGroup {
  label: string
  items: NavItem[]
}

/**
 * Меню разделов. Бейдж «Заказы» здесь НЕ задан: раньше он был прибит числом
 * «12» и жил своей жизнью — админ видел счётчик, не имеющий отношения к его
 * данным. Теперь число подставляет `groups` из ответа `GET /orders`.
 */
const NAV_GROUPS: NavGroup[] = [
  {
    label: 'Продажи',
    items: [
      { label: 'Обзор', to: '/admin', icon: '◧' },
      { label: 'Заказы', to: '/admin/orders', icon: '◫' },
      { label: 'Билеты', to: '/admin/tickets', icon: '◨' },
      { label: 'Платежи', to: '/admin/payments', icon: '◊' },
      // Приём платежей: ключи ЮKassa и demo-режим. Отдельный пункт от
      // «Платежей»: там журнал операций, здесь — настройка шлюза.
      { label: 'Приём платежей', to: '/admin/payment-settings', icon: '₽' },
    ],
  },
  {
    label: 'События',
    items: [
      { label: 'Мероприятия', to: '/admin/events', icon: '▤' },
      { label: 'Сеансы', to: '/admin/sessions', icon: '◷' },
      { label: 'Площадки', to: '/admin/venues', icon: '⌂' },
      { label: 'Схемы залов', to: '/admin/halls', icon: '▦' },
    ],
  },
  {
    label: 'Витрина',
    items: [
      { label: 'Конструктор', to: '/admin/storefront', icon: '▣' },
      // Конструктор билета. Пункт меню добавлен вместе с разделом: до этого
      // страницы не существовало, а компонент TicketBuilder.vue (1252 строки)
      // был недостижим из UI вообще.
      { label: 'Шаблоны билетов', to: '/admin/ticket-templates', icon: '🎫' },
      // Библиотека файлов. Раздел добавлен вместе со страницей: модуль Media
      // отдавал эндпоинты, но экрана к ним не было — файл, привязанный к
      // удалённому мероприятию, оставался в хранилище навсегда.
      { label: 'Файлы', to: '/admin/media', icon: '🖼' },
    ],
  },
  {
    label: 'Настройки',
    items: [
      { label: 'Пользователи', to: '/admin/users', icon: '☺' },
      { label: 'Интеграции', to: '/admin/integrations', icon: '⇄' },
      { label: 'Вебхуки', to: '/admin/webhooks', icon: '⌁' },
      { label: 'Шаблоны писем', to: '/admin/notification-templates', icon: '✉' },
      { label: 'Почта (SMTP)', to: '/admin/mail', icon: '⚙' },
      { label: 'Аналитика', to: '/admin/analytics', icon: '▲' },
    ],
  },
]

interface OrgRow {
  id?: number
  public_id?: string
  name: string
}

/**
 * Организации — из API, а не из списка-заглушки.
 *
 * Раньше здесь лежали три выдуманные компании («Театр "Кукольный дом"» и т.п.),
 * и переключатель выглядел рабочим, ничего не переключая: выбранное значение
 * никуда не отправлялось. Пока у пользователя нет доступных организаций,
 * селектор не показываем вовсе — пустой список честнее фальшивого.
 */
const organizations = ref<OrgRow[]>([])
const currentOrg = usePersistedRef('admin.org', '')

/** Заказы, ждущие оплаты: реальный счётчик для бейджа. */
const awaitingOrders = ref<number | null>(null)

const groups = computed(() =>
  NAV_GROUPS.map((group) => ({
    ...group,
    items: group.items.map((item) =>
      item.to === '/admin/orders' && awaitingOrders.value
        ? { ...item, badge: String(awaitingOrders.value) }
        : item,
    ),
  })),
)

/** Инициалы текущего пользователя для кнопки профиля (было жёстко «АК»). */
const initials = computed(() => {
  const user = currentUser()
  const first = user?.first_name?.trim().charAt(0) ?? ''
  const last = user?.last_name?.trim().charAt(0) ?? ''
  const fromName = `${first}${last}`.toUpperCase()
  return fromName || (user?.email?.trim().charAt(0) ?? 'А').toUpperCase()
})

/** Ответ может прийти как { data: [...] }, так и уже развёрнутым массивом. */
function rows<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[]
  const inner = (payload as { data?: unknown } | null)?.data
  return Array.isArray(inner) ? (inner as T[]) : []
}

async function loadOrganizations(): Promise<void> {
  try {
    const res = await get<unknown>('/organizations?limit=50')
    organizations.value = rows<OrgRow>(res.data)
  } catch {
    // 403/сеть — переключатель просто не показываем.
    organizations.value = []
  }
  if (!organizations.value.some((o) => o.name === currentOrg.value)) {
    currentOrg.value = organizations.value[0]?.name ?? ''
  }
}

async function loadAwaitingOrders(): Promise<void> {
  try {
    const res = await get<unknown[]>('/orders?status=awaiting_payment&per_page=1')
    const total = (res.meta as { total?: number } | undefined)?.total
    awaitingOrders.value = typeof total === 'number' && total > 0 ? total : null
  } catch {
    awaitingOrders.value = null
  }
}

onMounted(() => {
  void loadOrganizations()
  void loadAwaitingOrders()
})
</script>

<template>
  <div class="flex min-h-dvh bg-canvas">
    <!-- Сайдбар: на десктопе постоянный, на мобильном — выезжающий -->
    <aside
      :class="[
        'fixed inset-y-0 left-0 z-40 flex flex-col border-r border-line bg-surface transition-all duration-200 ease-out md:sticky md:top-0 md:h-dvh',
        collapsed ? 'w-[72px]' : 'w-64',
        ui.navOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
      ]"
    >
      <div class="flex h-16 flex-none items-center gap-2.5 px-4">
        <span
          class="grid h-9 w-9 flex-none place-items-center rounded-lg bg-brand-gradient text-base font-bold text-white shadow-brand"
          aria-hidden="true"
        >Н</span>
        <span v-if="!collapsed" class="truncate text-base font-bold tracking-tight text-content">NABILET</span>
      </div>

      <nav class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
        <div v-for="group in groups" :key="group.label" class="mb-4">
          <p v-if="!collapsed" class="px-2.5 pb-1.5 pt-2 text-2xs font-semibold uppercase tracking-wider text-subtle">
            {{ group.label }}
          </p>
          <RouterLink
            v-for="item in group.items"
            :key="item.to"
            :to="item.to"
            :title="item.label"
            class="group mb-0.5 flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-muted transition-colors hover:bg-surface-3 hover:text-content"
            active-class="!bg-brand-500/12 !text-brand-300"
            @click="ui.navOpen = false"
          >
            <span aria-hidden="true" class="grid h-5 w-5 flex-none place-items-center text-base">{{ item.icon }}</span>
            <span v-if="!collapsed" class="flex-1 truncate">{{ item.label }}</span>
            <span
              v-if="!collapsed && item.badge"
              class="flex-none rounded-full bg-accent-500/20 px-1.5 text-2xs font-semibold text-accent-300"
            >{{ item.badge }}</span>
          </RouterLink>
        </div>
      </nav>

      <div class="flex-none border-t border-line p-2">
        <button
          type="button"
          class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-muted transition-colors hover:bg-surface-3 hover:text-content"
          :title="collapsed ? 'Развернуть меню' : 'Свернуть меню'"
          :aria-expanded="!collapsed"
          @click="collapsed = !collapsed"
        >
          <span aria-hidden="true" class="grid h-5 w-5 flex-none place-items-center">{{ collapsed ? '»' : '«' }}</span>
          <span v-if="!collapsed">Свернуть</span>
        </button>
      </div>
    </aside>

    <div v-if="ui.navOpen" class="fixed inset-0 z-30 bg-overlay/60 md:hidden" @click="ui.navOpen = false" />

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="glass sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line px-4 sm:px-6">
        <button
          type="button"
          class="grid h-11 w-11 flex-none place-items-center rounded-lg border border-line text-sm md:hidden"
          aria-label="Открыть меню"
          @click="ui.navOpen = !ui.navOpen"
        >☰</button>

        <!-- Переключатель организации: мультитенантность видна всегда.
             Список — из API; пока организаций нет, селектор не рендерим. -->
        <div v-if="organizations.length > 0" class="relative min-w-0">
          <select
            v-model="currentOrg"
            aria-label="Текущая организация"
            class="h-11 w-full max-w-[16rem] cursor-pointer appearance-none rounded-lg border border-line bg-surface-2 pl-3 pr-8 text-sm text-content focus:border-brand-400 focus:outline-none"
          >
            <option v-for="org in organizations" :key="org.id ?? org.public_id ?? org.name" :value="org.name">{{ org.name }}</option>
          </select>
          <span aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-subtle">▾</span>
        </div>

        <div class="flex-1" />

        <!-- Командная палитра: Ctrl+K привычнее любого меню -->
        <button
          type="button"
          class="flex h-11 items-center gap-2 rounded-lg border border-line px-2.5 text-xs text-subtle transition-colors hover:text-content"
          @click="ui.commandOpen = true"
        >
          <span aria-hidden="true">⌕</span>
          <span class="hidden sm:inline">Поиск</span>
          <kbd class="hidden rounded border border-line px-1 font-mono text-2xs sm:inline">Ctrl K</kbd>
        </button>

        <button
          type="button"
          class="grid h-11 w-11 flex-none place-items-center rounded-lg border border-line text-sm text-muted transition-colors hover:text-content"
          :aria-label="ui.isDark ? 'Включить светлую тему' : 'Включить тёмную тему'"
          @click="ui.toggleTheme()"
        >{{ ui.isDark ? '☾' : '☀' }}</button>

        <button
                  type="button"
                  class="grid h-11 w-11 flex-none place-items-center rounded-full bg-surface-3 text-sm text-content"
                  aria-label="Профиль"
                  @click="router.push('/')"
                >{{ initials }}</button>

                <button
                  type="button"
                  class="grid h-11 w-11 flex-none place-items-center rounded-lg border border-line text-sm text-muted transition-colors hover:border-rose-400/40 hover:text-rose-400"
                  aria-label="Выйти из админки"
                  title="Выйти"
                  @click="doLogout"
                >⏻</button>
              </header>

      <main class="min-w-0 flex-1 px-4 py-5 sm:px-6 sm:py-6">
        <router-view />
      </main>
    </div>
  </div>
</template>
