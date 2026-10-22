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
import { isCustomerSession, ensureCustomerSession } from '@/lib/customer'
import { rememberReferralCode } from '@/lib/referral'
import { applySeo } from '@/lib/seo'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: StorefrontShell,
    children: [
      { path: '', name: 'catalog', component: () => import('@/pages/storefront/CatalogPage.vue'), meta: { title: 'Афиша мероприятий' } },
      { path: 'event/:slug', name: 'event', component: () => import('@/pages/storefront/EventPage.vue'), meta: { title: 'Мероприятие' } },
      {
        /*
         * Отдельного экрана выбора мест больше нет: покупка происходит на
         * странице события (`PurchaseWidget`), без переходов. Сам маршрут
         * оставлен РЕДИРЕКТОМ, а не удалён: ссылки `/event/{slug}/seats`
         * сидят в письмах, закладках и в поисковой выдаче, а `?session=`
         * переносится — страница события открывает тот же сеанс, который
         * человек выбирал раньше. Удалённый маршрут отдал бы «страницу не
         * найдена» на ровном месте.
         */
        path: 'event/:slug/seats',
        name: 'seats',
        redirect: (to) => ({ path: `/event/${String(to.params.slug)}`, query: to.query }),
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
       * «Клуб зрителей», фаза 1: избранное и подписки.
       *
       * Отдельные маршруты, а не разделы кабинета: избранное открывают «просто
       * посмотреть», мимо профиля и пароля. Оба экрана доступны только с
       * аккаунтом — `/me/*` иначе ответит 401, а guard уведёт на вход с
       * возвратом сюда (`?redirect=/favorites`).
       */
      {
        path: 'favorites',
        name: 'favorites',
        component: () => import('@/pages/storefront/FavoritesPage.vue'),
        meta: { title: 'Избранное', requiresCustomer: true },
      },
      {
        path: 'subscriptions',
        name: 'subscriptions',
        component: () => import('@/pages/storefront/SubscriptionsPage.vue'),
        meta: { title: 'Подписки', requiresCustomer: true },
      },
      {
        // Фаза 2a: баланс и история бонусного счёта. Отдельный экран по той же
        // причине, что «Избранное»: за балансом заходят «посмотреть», мимо
        // профиля и пароля. `requiresCustomer` — потому что `/me/bonus` иначе
        // ответит 401.
        path: 'bonus',
        name: 'bonus',
        component: () => import('@/pages/storefront/BonusPage.vue'),
        meta: { title: 'Бонусы', requiresCustomer: true },
      },
      {
        /*
         * Фаза 4: посадочная страница приглашения `#/r/{code}`.
         *
         * Объявлена обычным маршрутом, а не `beforeEnter`-редиректом: страница
         * объясняет, зачем друг прислал ссылку и что даёт первая покупка.
         * Тот же хост и тот же hash-роутинг, поэтому ссылка работает из
         * письма и мессенджера без правок на сервере (SPA отдаётся только на
         * `/`, см. `routes/web.php`).
         *
         * `requiresCustomer` намеренно НЕТ: друга приглашают ДО регистрации, и
         * требовать аккаунт на этом экране — значит потерять приглашение.
         */
        path: 'r/:code',
        name: 'referral',
        component: () => import('@/pages/storefront/ReferralLandingPage.vue'),
        meta: { title: 'Приглашение в клуб' },
      },
      {
        // «Клуб зрителей»: экран «Моя коллекция» (ачивки, уровень, бонусы) по
        // брифу геймификации. Отдельный маршрут, как и /bonus: кабинет — лишь
        // точка входа, а сам клуб смотрят «посмотреть». `requiresCustomer` —
        // данные клуба уходят в `/me/*`.
        path: 'club',
        name: 'club',
        component: () => import('@/pages/storefront/ClubPage.vue'),
        meta: { title: 'Клуб зрителей', requiresCustomer: true },
      },
      {
        /*
         * Фаза 5: «Мои розыгрыши» — билеты «счастливого билета».
         *
         * Маршрут под `requiresCustomer`, как «Бонусы» и «Клуб»: данные
         * приходят из `/me/raffles`, а билеты покупателя — не публичная
         * статистика. Экран показывает участия, а итоги розыгрыша ведут на
         * публичную страницу (`/raffles/{public_id}` ниже) — она общая для
         * всех и открывается без аккаунта.
         */
        path: 'my-raffles',
        name: 'my-raffles',
        component: () => import('@/pages/storefront/MyRafflesPage.vue'),
        meta: { title: 'Мои розыгрыши', requiresCustomer: true },
      },
      {
        /*
         * Фаза 5: публичная страница итогов розыгрыша.
         *
         * `requiresCustomer` намеренно НЕТ: страницу открывают из писем и
         * ссылок, а победители на ней показаны маской (`RaffleResultsResource`)
         * — открывать состав участников аккаунтом незачем. Путь несёт хэш —
         * тот же механизм, что у приглашения `/r/{code}`: ссылка работает из
         * письма без правок на сервере.
         */
        path: 'raffles/:publicId',
        name: 'raffle-results',
        component: () => import('@/pages/storefront/RaffleResultsPage.vue'),
        meta: { title: 'Итоги розыгрыша' },
      },

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
      // Центр помощи админа: настройка системы, запуск концерта в продажу,
      // все разделы. Статическая страница — эндпоинтов не требует.
      { path: 'help', name: 'admin-help', component: () => import('@/pages/admin/AdminHelpPage.vue') },
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
      // Приём платежей: ключи ЮKassa, demo-режим, провайдер по умолчанию.
      // Объявлен до :section, иначе catch-all показывал бы заглушку.
      { path: 'payment-settings', name: 'admin-payment-settings', component: () => import('@/pages/admin/AdminPaymentSettingsPage.vue') },
      // «Клуб зрителей»: ранний доступ и реферальная программа. Объявлен до
      // :section по той же причине, что и payment-settings.
      { path: 'club-settings', name: 'admin-club-settings', component: () => import('@/pages/admin/AdminClubSettingsPage.vue') },
      // Розыгрыши «счастливый билет»: состав участников, призовые места и
      // тираж. Объявлен до :section — иначе catch-all показывал бы заглушку.
      { path: 'raffles', name: 'admin-raffles', component: () => import('@/pages/admin/AdminRafflesPage.vue') },
      // Matrix (Element X): административные уведомления в комнату через
      // удалённый Synapse. Объявлен до :section по той же причине, что и
      // raffles — иначе catch-all показывал бы «Раздел не найден».
      { path: 'matrix', name: 'admin-matrix', component: () => import('@/pages/admin/AdminMatrixSettingsPage.vue') },
      // Логи приложения: записи с уровнями, поиском и стектрейсами. Объявлен
      // до :section — иначе catch-all съедал бы путь и показывал заглушку.
      { path: 'logs', name: 'admin-logs', component: () => import('@/pages/admin/AdminLogsPage.vue') },
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

/*
 * Фаза 4: атрибуция по `?ref={code}` на ЛЮБОМ адресе.
 *
 * Ссылка `/r/{code}` ведёт на свой маршрут, но приглашение рассылают и в
 * обход него — «перейди по afisha.ru/?ref=CODE» в переписке. Ловим параметр
 * глобально, до guard'ов: код должен запомниться, даже если дальше человек
 * уйдёт на вход (`?ref=` при этом теряется вместе с query при переходе, но
 * код уже в localStorage).
 *
 * Запоминает только `rememberReferralCode()` — она не хранит заведомо
 * битые значения, поэтому мусорный `?ref=` не засоряет localStorage.
 */
router.beforeEach((to) => {
  const ref = to.query.ref
  if (typeof ref === 'string') rememberReferralCode(ref)
})

// Guard: админка требует админскую сессию — токен И роль. Без неё — на логин.
// Асинхронный: ниже проверяется живость сессии покупателя запросом к серверу.
router.beforeEach(async (to) => {
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
   *
   * НАЛИЧИЕ ТОКЕНА — ЕЩЁ НЕ СЕССИЯ
   *   `isCustomerSession()` читает localStorage и не знает, что токен уже
   *   истёк, отозван выходом на другом устройстве или сменой пароля (она
   *   отзывает все сессии), а на стенде — исчез вместе с пересозданной базой.
   *   Такой токен проходил guard, страница кабинета грузилась, `/me/*` отвечали
   *   401, и покупатель видел клуб с нулями вместо формы входа. Поэтому перед
   *   допуском спрашиваем сервер (`ensureCustomerSession()` → `GET /me`), но
   *   только один раз на токен: проверка кэшируется, навигация не ждёт сеть
   *   каждый раз.
   */
  const needsCustomer = to.matched.some((r) => r.meta.requiresCustomer)

  if (needsCustomer) {
    if (!isCustomerSession()) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    if (!(await ensureCustomerSession())) {
      // `reason` — чтобы форма входа объяснила, почему покупатель здесь, а не
      // молча выбросила его с открытой страницы.
      return { name: 'login', query: { redirect: to.fullPath, reason: 'expired' } }
    }
  }

  // Уже вошли — формы входа и регистрации показывать повторно незачем.
  //
  // Сессию здесь тоже проверяем: с мёртвым токеном этот переход увёл бы
  // покупателя на `/account`, оттуда guard кабинета вернул бы его на `/login` —
  // и форма мигала бы двумя лишними переходами. Проверка кэшируется, так что
  // для живой сессии это ноль запросов.
  if ((to.name === 'login' || to.name === 'register') && isCustomerSession()) {
    if (await ensureCustomerSession()) return { name: 'account' }
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
