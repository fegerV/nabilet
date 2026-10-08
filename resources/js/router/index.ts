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
        // Демо-подтверждение оплаты. Путь совпадает с
        // `YooKassaProvider::DEMO_CONFIRM_PATH` — разъехавшись, они дают 404
        // после «оплаты» (проверено: покупатель уходил в никуда).
        path: 'checkout/demo-pay',
        name: 'demo-pay',
        component: () => import('@/pages/storefront/DemoPayPage.vue'),
      },
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
      { path: 'sessions/:id/prices', name: 'admin-session-prices', component: () => import('@/pages/admin/AdminSessionPricesPage.vue') },
      {
              path: 'halls',
              name: 'admin-halls',
              component: () => import('@/pages/admin/AdminHallsPage.vue'),
            },
            {
              path: 'halls/:publicId/editor',
              name: 'admin-hall-editor',
              component: () => import('@/pages/hall-editor/HallEditorPage.vue'),
            },
      // Конструктор витрины: объявлен до :section, иначе catch-all съедал бы путь.
      { path: 'storefront', name: 'admin-storefront', component: () => import('@/pages/admin/AdminStorefrontPage.vue') },
      // Шаблоны билетов: конструктор макета билета. Объявлен до :section по той
      // же причине, что и storefront, — иначе catch-all показывал бы «Раздел не
      // найден». Открытый шаблон живёт в query (`?template=12`), а не в
      // отдельном маршруте: конструктор — это тот же раздел с открытым макетом.
      { path: 'ticket-templates', name: 'admin-ticket-templates', component: () => import('@/pages/admin/AdminTicketTemplatesPage.vue') },
      // Библиотека файлов: афиши, галереи, документы, видео организации. Объявлена
      // до :section по той же причине, что storefront и ticket-templates, — иначе
      // catch-all показал бы «Раздел не найден». До этого экрана не было вовсе:
      // модуль Media отдавал пять эндпоинтов, но посмотреть файлы было негде.
      { path: 'media', name: 'admin-media', component: () => import('@/pages/admin/AdminMediaPage.vue') },
      // Реальные разделы вместо заглушек — на свои эндпоинты.
      { path: 'tickets', name: 'admin-tickets', component: () => import('@/pages/admin/AdminTicketsPage.vue') },
      { path: 'payments', name: 'admin-payments', component: () => import('@/pages/admin/AdminPaymentsPage.vue') },
      { path: 'users', name: 'admin-users', component: () => import('@/pages/admin/AdminUsersPage.vue') },
      { path: 'analytics', name: 'admin-analytics', component: () => import('@/pages/admin/AdminAnalyticsPage.vue') },
      { path: 'integrations', name: 'admin-integrations', component: () => import('@/pages/admin/AdminIntegrationsPage.vue') },
      // Транзакционная почта и исходящие вебхуки: объявлены до :section,
      // иначе catch-all съедал бы пути и показывал заглушку.
      { path: 'notification-templates', name: 'admin-notification-templates', component: () => import('@/pages/admin/AdminNotificationTemplatesPage.vue') },
      { path: 'webhooks', name: 'admin-webhooks', component: () => import('@/pages/admin/AdminWebhooksPage.vue') },
      { path: 'mail', name: 'admin-mail', component: () => import('@/pages/admin/AdminMailSettingsPage.vue') },
      // Catch-all для неизвестных адресов админки. Разделов-заглушек больше нет:
      // все пункты меню ведут на реальные экраны, поэтому единственное, чем может
      // быть этот маршрут, — опечатка в URL. Он отдаёт честный «Раздел не найден»
      // вместо прежнего «ещё не реализован», который для несуществующего раздела
      // прямо вводил в заблуждение.
      { path: ':section', name: 'admin-not-found', component: () => import('@/pages/admin/AdminNotFoundPage.vue') },
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
