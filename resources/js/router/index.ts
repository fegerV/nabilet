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

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: StorefrontShell,
    children: [
      { path: '', name: 'catalog', component: () => import('@/pages/storefront/CatalogPage.vue') },
      { path: 'event/:id', name: 'event', component: () => import('@/pages/storefront/EventPage.vue') },
      {
        path: 'event/:id/seats',
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
    path: '/admin',
    component: AdminShell,
    children: [
      { path: '', name: 'admin', component: () => import('@/pages/admin/AdminDashboardPage.vue') },
      { path: 'orders', name: 'admin-orders', component: () => import('@/pages/admin/AdminOrdersPage.vue') },
      { path: 'events', name: 'admin-events', component: () => import('@/pages/admin/AdminEventsPage.vue') },
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
