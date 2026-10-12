/**
 * Маршруты.
 *
 * Hash-история выбрана сознательно: SPA живёт внутри Laravel, а embed-виджет —
 * внутри чужого <iframe>, где красивые URL невозможны без переписывания на
 * сервере. Хэш работает везде одинаково и не требует настройки nginx.
 */
import { createRouter, createWebHashHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router'
import StorefrontShell from '@/layouts/StorefrontShell.vue'
import AdminShell from '@/layouts/AdminShell.vue'
import { getToken } from '@/lib/api'
import { isAdminSession, clearSession } from '@/lib/auth'
import { isCustomerSession } from '@/lib/customer'
import { applySeo } from '@/lib/seo'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: StorefrontShell,
    children: [
      { path: '', name: 'catalog', component: () => import('@/pages/storefront/CatalogPage.vue'), meta: { title: 'Афиша мероприятий' } },
      { path: 'event/:slug', name: 'event', component: () => import('@/pages/storefront/EventPage.vue'), meta: { title: 'Мероприятие' } },
      {
        path: 'event/:slug/seats',
        name: 'seats',
        component: () => import('@/pages/storefront/SeatSelectionPage.vue'),
        meta: { title: 'Выбор мест' },
      },
      { path: 'checkout', name: 'checkout', component: () => import('@/pages/storefront/CheckoutPage.vue'), meta: { title: 'Оформление заказа' } },
      {
        // Демо-подтверждение оплаты. Путь совпадает с
        // `YooKassaProvider::DEMO_CONFIRM_PATH` — разъехавшись, они дают 404
        // после «оплаты» (проверено: покупатель уходил в никуда).
        path: 'checkout/demo-pay',
        name: 'demo-pay',
        component: () => import('@/pages/storefront/DemoPayPage.vue'),
        meta: { title: 'Подтверждение оплаты' },
      },
      {
        path: 'payment/:result',
        name: 'payment',
        component: () => import('@/pages/storefront/PaymentResultPage.vue'),
        meta: { title: 'Результат оплаты' },
      },
      { path: 'tickets', name: 'tickets', component: () => import('@/pages/storefront/TicketsPage.vue'), meta: { title: 'Мои билеты' } },

      /*
       * Кабинет покупателя. Живёт на витрине, а не в админке: это та же
       * оболочка с шапкой и корзиной, потому что покупатель приходит сюда за
       * билетами, а не «работать». Пути намеренно без префикса (`/login`, а не
       * `/account/login`) — их диктует ссылка из письма о сбросе пароля:
       * `AccountMailService::actionUrl()` собирает `/#/reset-password?...`, и
       * переименование маршрута разошлось бы с уже отправленными письмами.
       */
      { path: 'login', name: 'login', component: () => import('@/pages/storefront/LoginPage.vue'), meta: { title: 'Вход' } },
      { path: 'register', name: 'register', component: () => import('@/pages/storefront/RegisterPage.vue'), meta: { title: 'Регистрация' } },
      {
        path: 'forgot-password',
        name: 'forgot-password',
        component: () => import('@/pages/storefront/ForgotPasswordPage.vue'),
        meta: { title: 'Восстановление пароля' },
      },
      {
        path: 'reset-password',
        name: 'reset-password',
        component: () => import('@/pages/storefront/ResetPasswordPage.vue'),
        meta: { title: 'Новый пароль' },
      },
      {
        path: 'account',
        name: 'account',
        component: () => import('@/pages/storefront/AccountPage.vue'),
        meta: { title: 'Личный кабинет', requiresCustomer: true },
      },
    ],
  },
  {
    path: '/admin/login',
    name: 'admin-login',
    component: () => import('@/pages/admin/AdminLoginPage.vue'),
    meta: { title: 'Вход в админку' },
  },
  {
    path: '/admin',
    component: AdminShell,
    meta: { requiresAuth: true, title: 'Админка' },
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

// Guard: админка требует админскую сессию — токен И роль. Без неё — на логин.
router.beforeEach((to) => {
  if (to.matched.some((r) => r.meta.requiresAuth)) {
    if (!getToken()) {
      return { name: 'admin-login', query: { redirect: to.fullPath } }
    }
    // D2: токен без роли admin/manager — не повод пускать в оболочку.
    // Сессия чистится, иначе чужой токен остаётся в localStorage навсегда.
    if (!isAdminSession()) {
      clearSession()
      return { name: 'admin-login', query: { redirect: to.fullPath, reason: 'forbidden' } }
    }
  }
  // Уже залогинен на странице входа — не показываем форму повторно.
  if (to.name === 'admin-login' && isAdminSession()) {
    return { name: 'admin' }
  }

  /*
   * Кабинет покупателя требует сессию ВИТРИНЫ, и проверяется она отдельным
   * ключом (`isCustomerSession()` → `nabilet_customer_token`), а не `getToken()`.
   * Это не дублирование: токены сотрудника и покупателя намеренно разведены
   * (см. `CUSTOMER_TOKEN_KEY` в `api.ts`), и если бы guard кабинета смотрел на
   * общий ключ, покупатель с сотрудническим токеном в том же браузере увидел бы
   * чужой кабинет.
   */
  if (to.matched.some((r) => r.meta.requiresCustomer) && !isCustomerSession()) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // Уже вошли — формы входа и регистрации показывать повторно незачем.
  if ((to.name === 'login' || to.name === 'register') && isCustomerSession()) {
    return { name: 'account' }
  }
})

/**
 * E3: заголовок вкладки и метаданные из `meta.title` маршрута.
 *
 * Раньше hash-SPA отдавал на всех адресах один заголовок из `index.html` —
 * в истории браузера и в закладках все страницы выглядели одинаково.
 * Берём самый глубокий совпавший маршрут: у детей админки своего заголовка
 * нет, поэтому им достаётся родительский «Админка», а не пустая строка.
 *
 * Страницы с данными переопределяют заголовок ПОЗЖЕ (см. `useSeo` в
 * `EventPage`): переход срабатывает до загрузки ответа, поэтому здесь всегда
 * только запасной вариант.
 */
function titleFor(to: RouteLocationNormalized): string | null {
  for (let i = to.matched.length - 1; i >= 0; i -= 1) {
    const title = to.matched[i]?.meta.title
    if (typeof title === 'string' && title) return title
  }
  return null
}

router.afterEach((to) => {
  applySeo({ title: titleFor(to) })
})
