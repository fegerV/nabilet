/**
 * Маршруты.
 *
 * Hash-история выбрана сознательно: SPA живёт внутри Laravel, а embed-виджет —
 * внутри чужого <iframe>, где красивые URL невозможны без переписывания на
 * сервере. Хэш работает везде одинаково и не требует настройки nginx.
 */
import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import StorefrontShell from '@/layouts/StorefrontShell.vue'
import AdminShell from '@/layouts/AdminShell.vue'
import { getToken } from '@/lib/api'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: StorefrontShell,
    children: [
      { path: '', name: 'catalog', component: () => import('@/pages/storefront/CatalogPage.vue') },
      { path: 'event/:slug', name: 'event', component: () => import('@/pages/storefront/EventPage.vue') },
      {
        path: 'event/:slug/seats',
        name: 'seats',
        component: () => import('@/pages/storefront/SeatSelectionPage.vue'),
      },
      { path: 'checkout', name: 'checkout', component: () => import('@/pages/storefront/CheckoutPage.vue') },
      {
        path: 'payment/:result',
        name: 'payment',
        component: () => import('@/pages/storefront/PaymentResultPage.vue'),
      },
      { path: 'tickets', name: 'tickets', component: () => import('@/pages/storefront/TicketsPage.vue') },
    ],
  },
  {
    path: '/admin/login',
    name: 'admin-login',
    component: () => import('@/pages/admin/AdminLoginPage.vue'),
  },
  {
    path: '/admin',
    component: AdminShell,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'admin', component: () => import('@/pages/admin/AdminDashboardPage.vue') },
            { path: 'orders', name: 'admin-orders', component: () => import('@/pages/admin/AdminOrdersPage.vue') },
            { path: 'events', name: 'admin-events', component: () => import('@/pages/admin/AdminEventsPage.vue') },
      { path: 'events/new', name: 'admin-event-new', component: () => import('@/pages/admin/AdminEventFormPage.vue') },
      { path: 'events/:id', name: 'admin-event-edit', component: () => import('@/pages/admin/AdminEventFormPage.vue') },
            { path: 'venues', name: 'admin-venues', component: () => import('@/pages/admin/AdminVenuesPage.vue') },
            { path: 'sessions', name: 'admin-sessions', component: () => import('@/pages/admin/AdminSessionsPage.vue') },
      {
        path: 'halls',
        name: 'admin-halls',
        component: () => import('@/pages/hall-editor/HallEditorPage.vue'),
      },
      { path: ':section', name: 'admin-section', component: () => import('@/pages/admin/AdminPlaceholderPage.vue') },
    ],
  },
]

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior(_to, _from, savedPosition) {
    // Возврат по «назад» восстанавливает позицию: в списке заказов это важно.
    return savedPosition ?? { top: 0 }
  },
})

// Guard: админка требует токен. Без него — редирект на логин.
router.beforeEach((to) => {
  if (to.matched.some((r) => r.meta.requiresAuth)) {
    if (!getToken()) {
      return { name: 'admin-login', query: { redirect: to.fullPath } }
    }
  }
  // Уже залогинен на странице входа — не показываем форму повторно.
  if (to.name === 'admin-login' && getToken()) {
    return { name: 'admin' }
  }
})
