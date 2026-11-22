<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import AccountOrderList from '@/components/storefront/AccountOrderList.vue'
import { changePassword, clearCustomerSession, customerUser, fetchMe, fetchMyOrdersPage, fetchSessions, logoutCustomer, revokeSession, updateMe, type CustomerOrder, type CustomerSession, type CustomerUser } from '@/lib/customer'
import { ApiError } from '@/lib/api'
import { dateTime, dateFull, time } from '@/lib/format'
import { useLoyaltyStore } from '@/stores/loyalty'
import { achievementCollection, achievementImage, fetchClub, type ClubSnapshot } from '@/lib/achievementsCatalog'
import { fetchMyTickets } from '@/lib/tickets'
import { upcomingTickets } from '@/lib/accountOverview'
import type { TicketCard } from '@/lib/types'

const router = useRouter()
const route = useRoute()
const loyalty = useLoyaltyStore()
const tabs = [{ id: 'overview', label: 'Обзор', icon: '◫' }, { id: 'orders', label: 'Мои заказы', icon: '▤' }, { id: 'profile', label: 'Личные данные', icon: '○' }, { id: 'security', label: 'Безопасность', icon: '◇' }]
const tab = computed(() => tabs.some(t => t.id === route.query.tab) ? String(route.query.tab) : 'overview')
const me = ref<CustomerUser | null>(customerUser())
const loading = ref(true)
const loadError = ref('')
const exiting = ref(false)
const displayName = computed(() => [me.value?.first_name, me.value?.last_name].filter(Boolean).join(' ').trim() || 'Зритель')
const initials = computed(() => ((me.value?.first_name?.[0] ?? '') + (me.value?.last_name?.[0] ?? '')).toUpperCase() || (me.value?.email?.[0] ?? 'Н').toUpperCase())
const profile = ref({ first_name: '', last_name: '', email: '', phone: '' })
const originalProfile = ref('')
const profileDirty = computed(() => originalProfile.value !== '' && JSON.stringify(profile.value) !== originalProfile.value)
const profileSaving = ref(false)
const profileError = ref('')
const profileFields = ref<Record<string, string>>({})
const profileSaved = ref(false)
function fillProfile(user: CustomerUser | null): void {
  profile.value = { first_name: user?.first_name ?? '', last_name: user?.last_name ?? '', email: user?.email ?? '', phone: user?.phone ?? '' }
  originalProfile.value = JSON.stringify(profile.value)
}
function resetProfile(): void { fillProfile(me.value); profileSaved.value = false; profileError.value = ''; profileFields.value = {} }
async function loadProfile(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try { me.value = await fetchMe(); fillProfile(me.value) }
  catch (e) { loadError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить профиль.' }
  finally { loading.value = false }
}
async function saveProfile(): Promise<void> {
  if (profileSaving.value || loading.value || loadError.value || !profileDirty.value) return
  profileSaving.value = true; profileError.value = ''; profileFields.value = {}; profileSaved.value = false
  try { me.value = await updateMe({ ...profile.value }); fillProfile(me.value); profileSaved.value = true }
  catch (e) {
    if (e instanceof ApiError && e.details) {
      for (const [key, messages] of Object.entries(e.details)) if (messages?.length) profileFields.value[key] = String(messages[0])
    }
    if (!Object.keys(profileFields.value).length) profileError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить профиль.'
  } finally { profileSaving.value = false }
}
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordAgain = ref('')
const showPasswords = ref(false)
const passwordSaving = ref(false)
const passwordError = ref('')
const passwordDone = ref(false)
async function savePassword(): Promise<void> {
  if (passwordSaving.value || loading.value || loadError.value) return
  passwordError.value = ''
  if (newPassword.value !== newPasswordAgain.value) { passwordError.value = 'Новые пароли не совпадают.'; return }
  passwordSaving.value = true
  try {
    await changePassword(newPassword.value, currentPassword.value || undefined)
    clearCustomerSession(); passwordDone.value = true
    currentPassword.value = ''; newPassword.value = ''; newPasswordAgain.value = ''
  } catch (e) {
    passwordError.value = e instanceof ApiError ? String(e.details?.current_password?.[0] ?? e.details?.password?.[0] ?? e.message) : 'Не удалось изменить пароль.'
  } finally { passwordSaving.value = false }
}
const orders = ref<CustomerOrder[]>([])
const ordersLoading = ref(true)
const ordersError = ref('')
const orderPage = ref(1)
const lastPage = ref(1)
const ordersTotal = ref<number | null>(null)
async function loadOrders(page = orderPage.value): Promise<void> {
  ordersLoading.value = true; ordersError.value = ''
  try {
    const result = await fetchMyOrdersPage(page)
    orders.value = result.orders; orderPage.value = result.page; lastPage.value = result.lastPage; ordersTotal.value = result.total
  } catch { ordersError.value = 'Не удалось загрузить заказы. Ваши покупки сохранены.' }
  finally { ordersLoading.value = false }
}
const sessions = ref<CustomerSession[]>([])
const sessionsLoading = ref(true)
const sessionsError = ref('')
const revokingId = ref<string | null>(null)
async function loadSessions(): Promise<void> {
  sessionsLoading.value = true; sessionsError.value = ''
  try { sessions.value = await fetchSessions() } catch { sessionsError.value = 'Не удалось загрузить устройства.' }
  finally { sessionsLoading.value = false }
}
async function endSession(session: CustomerSession): Promise<void> {
  if (revokingId.value) return
  revokingId.value = session.id
  try {
    await revokeSession(session.id)
    if (session.is_current) { exiting.value = true; clearCustomerSession(); await router.push('/login'); return }
    await loadSessions()
  } catch { sessionsError.value = 'Не удалось завершить сессию. Попробуйте ещё раз.' }
  finally { revokingId.value = null }
}
async function signOut(): Promise<void> {
  if (exiting.value) return
  if (profileDirty.value && !window.confirm('Выйти без сохранения изменений профиля?')) return
  exiting.value = true
  await logoutCustomer()
  await router.push('/')
}
function deviceLabel(session: CustomerSession): string {
  if (session.device_name) return session.device_name
  const ua = session.user_agent ?? ''
  const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Браузер'
  const device = /iPhone/.test(ua) ? 'iPhone' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Macintosh/.test(ua) ? 'macOS' : ''
  return ua ? [browser, device].filter(Boolean).join(' · ') : 'Неизвестное устройство'
}
function safeDate(value?: string | null): string { return value && !Number.isNaN(Date.parse(value)) ? dateTime(value) : 'Дата неизвестна' }
const tickets = ref<TicketCard[]>([])
const ticketsLoading = ref(true)
const ticketsError = ref(false)
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
const upcoming = computed(() => upcomingTickets(tickets.value, now.value))
const nextTicket = computed(() => upcoming.value[0])
async function loadTickets(): Promise<void> {
  ticketsLoading.value = true; ticketsError.value = false
  try { tickets.value = await fetchMyTickets() } catch { ticketsError.value = true }
  finally { ticketsLoading.value = false }
}
const club = ref<ClubSnapshot | null>(null)
const clubLoading = ref(true)
const clubError = ref(false)
const medals = computed(() => achievementCollection(club.value))
const earnedCount = computed(() => medals.value.filter(a => a.status === 'earned').length)
const featuredMedals = computed(() => [...medals.value.filter(a => !a.hidden)].sort((a, b) => Number(b.status === 'earned') - Number(a.status === 'earned')).slice(0, 3))
async function loadClub(): Promise<void> {
  clubLoading.value = true; clubError.value = false
  try { club.value = await fetchClub() } catch { club.value = null; clubError.value = true }
  finally { clubLoading.value = false }
}
function beforeUnload(event: BeforeUnloadEvent): void {
  if (profileDirty.value && !exiting.value && !passwordDone.value) { event.preventDefault(); event.returnValue = '' }
}
onBeforeRouteLeave(() => !profileDirty.value || exiting.value || passwordDone.value || window.confirm('В профиле есть несохранённые изменения. Покинуть страницу?'))
onMounted(() => {
  void loadProfile(); void loadOrders(1); void loadSessions(); void loadTickets(); void loadClub(); void loyalty.load()
  timer = setInterval(() => { now.value = Date.now() }, 60000)
  window.addEventListener('beforeunload', beforeUnload)
})
onUnmounted(() => { clearInterval(timer); window.removeEventListener('beforeunload', beforeUnload) })
</script>

<template>
  <div class="account-page mx-auto max-w-content px-4 py-6 sm:px-6">
    <section v-if="passwordDone" class="account-panel password-success">
      <span class="success-symbol">✓</span><h1>Пароль изменён</h1><p>Все устройства вышли из аккаунта. Войдите снова с новым паролем.</p>
      <RouterLink to="/login" class="account-primary">Войти в аккаунт</RouterLink>
    </section>
    <template v-else>
      <header class="account-heading"><div><p class="account-eyebrow">МОЙ NABILET</p><h1>Личный кабинет</h1><p>Ваши планы, билеты и любимые события — в одном месте.</p></div><RouterLink to="/" class="account-outline">Выбрать событие</RouterLink></header>
      <div class="account-layout">
        <aside class="account-sidebar">
          <div class="sidebar-profile"><span class="account-avatar">{{ initials }}</span><div><strong>{{ displayName }}</strong><span>{{ me?.email }}</span></div></div>
          <nav aria-label="Разделы личного кабинета" class="account-nav">
            <RouterLink v-for="item in tabs" :key="item.id" :to="{ path: '/account', query: item.id === 'overview' ? {} : { tab: item.id } }" :class="{ selected: tab === item.id }" :aria-current="tab === item.id ? 'page' : undefined"><span aria-hidden="true">{{ item.icon }}</span>{{ item.label }}</RouterLink>
            <span class="nav-divider" />
            <RouterLink to="/tickets"><span aria-hidden="true">▱</span>Мои билеты</RouterLink>
            <RouterLink to="/favorites"><span aria-hidden="true">♡</span>Избранное</RouterLink>
            <RouterLink to="/subscriptions"><span aria-hidden="true">♧</span>Подписки</RouterLink>
            <RouterLink to="/club"><span aria-hidden="true">✦</span>Клуб зрителей</RouterLink>
          </nav>
          <button class="account-logout" :disabled="exiting" @click="signOut">{{ exiting ? 'Выходим…' : 'Выйти из аккаунта' }}</button>
        </aside>
        <main class="account-main">
          <div v-if="loadError" class="account-alert" role="alert">{{ loadError }} <button @click="loadProfile">Повторить загрузку</button></div>
          <template v-if="tab === 'overview'">
            <section class="outing-card">
              <div class="outing-copy"><p class="account-eyebrow">{{ nextTicket ? 'ВАШ БЛИЖАЙШИЙ ВЫХОД' : 'ВПЕРЕДИ — НОВЫЕ ВПЕЧАТЛЕНИЯ' }}</p>
                <template v-if="ticketsLoading"><h2>Загружаем ваши планы…</h2><p>Собираем билеты в одном месте.</p></template>
                <template v-else-if="ticketsError"><h2>Ваши билеты на месте</h2><p>Сейчас не удалось загрузить их. Попробуйте ещё раз.</p><button class="account-primary" @click="loadTickets">Повторить загрузку</button></template>
                <template v-else-if="nextTicket"><h2>{{ nextTicket.eventTitle }}</h2><p class="outing-date">{{ dateFull(nextTicket.sessionAt) }} · {{ time(nextTicket.sessionAt) }}</p><p>{{ nextTicket.venue || 'Площадка уточняется' }}</p><RouterLink :to="{ path: '/tickets', query: nextTicket.orderId ? { order: String(nextTicket.orderId) } : {} }" class="account-primary">Открыть билет и QR</RouterLink></template>
                <template v-else><h2>{{ me?.first_name ? me.first_name + ', какой' : 'Какой' }} будет ваша следующая история?</h2><p>Предстоящих событий пока нет. Самое время найти концерт, спектакль или новое любимое место.</p><RouterLink to="/" class="account-primary">Посмотреть афишу</RouterLink><RouterLink v-if="tickets.length" to="/tickets" class="outing-secondary">Все мои билеты</RouterLink></template>
              </div>
              <div class="outing-art" aria-hidden="true"><div class="decorative-ticket"><span>NABILET</span><b>{{ nextTicket ? new Date(nextTicket.sessionAt).getDate() : '✦' }}</b><small>{{ nextTicket ? new Date(nextTicket.sessionAt).toLocaleDateString('ru-RU', { month: 'long' }) : 'ВАШ СЛЕДУЮЩИЙ ВЫХОД' }}</small><div class="ticket-perforation" /><i>МЕСТО ДЛЯ ВПЕЧАТЛЕНИЙ</i></div></div>
            </section>
            <section class="account-stats" aria-label="Ваш аккаунт в цифрах">
              <RouterLink to="/tickets"><span class="stat-icon">▱</span><div><strong>{{ ticketsLoading || ticketsError ? '—' : upcoming.length }}</strong><p>Билетов на будущие события</p></div></RouterLink>
              <RouterLink to="/bonus"><span class="stat-icon">✦</span><div><strong>{{ loyalty.bonus === null ? '—' : loyalty.bonusesCount.toLocaleString('ru-RU') }} <small>Б</small></strong><p>Бонусный счёт · 1 Б = 1 ₽</p></div></RouterLink>
              <RouterLink to="/favorites"><span class="stat-icon heart">♡</span><div><strong>{{ loyalty.loaded ? loyalty.favoritesCount : '—' }}</strong><p>Событий в избранном</p></div></RouterLink>
            </section>
            <div class="account-overview-grid">
              <section class="account-panel recent-orders"><div class="panel-heading"><h2>{{ orderPage === 1 ? 'Последние заказы' : 'Заказы · страница ' + orderPage }}</h2><RouterLink to="/account?tab=orders">Все заказы</RouterLink></div>
                <p v-if="ordersLoading" class="panel-state" role="status">Загружаем заказы…</p>
                <div v-else-if="ordersError" class="panel-state" role="alert"><p>{{ ordersError }}</p><button class="text-action" @click="loadOrders()">Повторить</button></div>
                <AccountOrderList v-else-if="orders.length" :orders="orders.slice(0, 3)" />
                <div v-else class="empty-account"><span>▤</span><h3>Первый заказ ещё впереди</h3><p>После покупки здесь появятся детали заказа и ссылка на билеты.</p><RouterLink to="/" class="text-action">Найти событие</RouterLink></div>
              </section>
              <section class="account-panel account-club"><div class="panel-heading"><h2>Ваш клуб зрителей</h2><span class="level-pill">{{ loyalty.loyaltyLevel?.name ?? '—' }}</span></div><p class="panel-intro">Собирайте медали за вечера, которые хочется помнить.</p>
                <div class="account-medals"><RouterLink v-for="medal in featuredMedals" :key="medal.id" to="/club" :aria-label="medal.name + (medal.status === 'earned' ? ', получено' : ', условия в клубе')"><img :src="achievementImage(medal.id)" alt="" width="100" height="100"><span>{{ medal.name }}</span><small v-if="medal.status === 'earned'">✓ Получено</small></RouterLink></div>
                <div class="club-summary"><span>{{ club ? earnedCount + ' из 15 медалей' : clubLoading ? 'Загружаем прогресс…' : 'Прогресс недоступен' }}</span><RouterLink to="/club">Открыть клуб</RouterLink></div><button v-if="clubError" class="text-action" @click="loadClub">Повторить загрузку</button>
              </section>
            </div>
            <section class="account-help"><span class="help-symbol">?</span><div><h2>Всё готово к вашему выходу</h2><p>В разделе «Мои билеты» можно открыть QR, сохранить его для входа и повторно отправить письмо.</p></div><RouterLink to="/tickets" class="account-outline">К моим билетам</RouterLink></section>
          </template>

          <section v-else-if="tab === 'orders'" class="account-panel">
            <div class="panel-heading"><div><h2>Мои заказы</h2><p class="panel-intro">История покупок, статусы и билеты.</p></div><NButton variant="ghost" size="sm" :loading="ordersLoading" @click="loadOrders()">Обновить</NButton></div>
            <p v-if="ordersLoading" class="panel-state" role="status">Загружаем заказы…</p>
            <div v-else-if="ordersError" class="panel-state" role="alert"><p>{{ ordersError }}</p><NButton class="mt-4" variant="secondary" @click="loadOrders()">Повторить</NButton></div>
            <template v-else-if="orders.length"><p class="order-hint">Нажмите на заказ, чтобы посмотреть состав и открыть билеты.</p><AccountOrderList :orders="orders" /><nav v-if="lastPage > 1" class="account-pagination" aria-label="Страницы заказов"><NButton variant="secondary" :disabled="orderPage <= 1" @click="loadOrders(orderPage - 1)">Назад</NButton><span>Страница {{ orderPage }} из {{ lastPage }} · Всего {{ ordersTotal }}</span><NButton variant="secondary" :disabled="orderPage >= lastPage" @click="loadOrders(orderPage + 1)">Далее</NButton></nav></template>
            <div v-else class="empty-account"><span>▤</span><h3>Заказов пока нет</h3><p>Здесь появятся покупки, оформленные на этот аккаунт.</p><RouterLink to="/" class="account-primary">Выбрать событие</RouterLink></div>
          </section>

          <section v-else-if="tab === 'profile'" class="account-panel settings-panel">
            <div class="panel-heading"><div><h2>Личные данные</h2><p class="panel-intro">Чтобы мы знали, как к вам обращаться и куда присылать билеты.</p></div><span class="settings-symbol" aria-hidden="true">○</span></div>
            <form class="account-form" @submit.prevent="saveProfile">
              <fieldset :disabled="loading || !!loadError || profileSaving">
                <div class="form-grid"><NInput v-model="profile.first_name" label="Имя" autocomplete="given-name" :error="profileFields.first_name" /><NInput v-model="profile.last_name" label="Фамилия" autocomplete="family-name" :error="profileFields.last_name" /></div>
                <NInput v-model="profile.email" type="email" label="Электронная почта" required autocomplete="email" inputmode="email" :disabled="!!me?.email_verified_at" :error="profileFields.email" :hint="me?.email_verified_at ? 'Адрес подтверждён. Его изменение через эту форму недоступно.' : 'На этот адрес отправляем билеты и сообщения о заказах.'" />
                <NInput v-model="profile.phone" type="tel" label="Телефон" autocomplete="tel" inputmode="tel" :error="profileFields.phone" />
              </fieldset>
              <p v-if="profileError" class="form-error" role="alert">{{ profileError }}</p><p v-if="Object.keys(profileFields).length" class="form-error" role="alert">Проверьте отмеченные поля.</p>
              <p v-if="profileSaved && !profileDirty" class="form-success" role="status">Профиль сохранён.</p>
              <div class="form-actions"><NButton type="submit" :loading="profileSaving" :disabled="loading || !!loadError || !profileDirty">Сохранить изменения</NButton><NButton v-if="profileDirty" variant="ghost" :disabled="profileSaving" @click="resetProfile">Отменить</NButton><span v-if="profileDirty" class="unsaved-label">Есть несохранённые изменения</span></div>
            </form>
            <p class="settings-note">Изменения профиля не переоформляют уже купленные билеты. Если письмо не пришло, отправьте его повторно из раздела «Мои билеты».</p>
          </section>

          <template v-else-if="tab === 'security'">
            <section class="account-panel settings-panel"><div class="panel-heading"><div><h2>Пароль и вход</h2><p class="panel-intro">Управляйте доступом к своему аккаунту.</p></div><span class="settings-symbol" aria-hidden="true">◇</span></div>
              <form class="account-form" @submit.prevent="savePassword"><fieldset :disabled="passwordSaving || loading || !!loadError">
                <NInput v-model="currentPassword" :type="showPasswords ? 'text' : 'password'" label="Текущий пароль" autocomplete="current-password" hint="Если аккаунт создан при покупке и пароль ещё не задан, оставьте поле пустым." />
                <NInput v-model="newPassword" :type="showPasswords ? 'text' : 'password'" label="Новый пароль" required autocomplete="new-password" hint="Минимум 12 символов: заглавные и строчные буквы, цифра и знак." />
                <NInput v-model="newPasswordAgain" :type="showPasswords ? 'text' : 'password'" label="Повторите новый пароль" required autocomplete="new-password" />
                <label class="show-password"><input v-model="showPasswords" type="checkbox">Показать пароли</label>
              </fieldset><p v-if="passwordError" class="form-error" role="alert">{{ passwordError }}</p><p class="settings-note">После смены пароля все устройства выйдут из аккаунта. Потребуется войти заново.</p><NButton type="submit" :loading="passwordSaving" :disabled="loading || !!loadError">Сохранить новый пароль</NButton></form>
            </section>
            <section class="account-panel"><div class="panel-heading"><div><h2>Устройства и сессии</h2><p class="panel-intro">Завершите вход на устройстве, которым больше не пользуетесь.</p></div><NButton variant="ghost" size="sm" :loading="sessionsLoading" @click="loadSessions">Обновить</NButton></div>
              <p v-if="sessionsLoading" class="panel-state" role="status">Загружаем устройства…</p><div v-else-if="sessionsError" class="panel-state" role="alert"><p>{{ sessionsError }}</p><button class="text-action" @click="loadSessions">Повторить</button></div>
              <ul v-else-if="sessions.length" class="session-list"><li v-for="session in sessions" :key="session.id"><span class="device-symbol" aria-hidden="true">▣</span><div><h3>{{ deviceLabel(session) }} <span v-if="session.is_current" class="current-session">Это устройство</span></h3><p>Последняя активность: {{ safeDate(session.last_seen_at || session.created_at) }}</p><small>Вход: {{ safeDate(session.created_at) }}</small></div><NButton variant="secondary" size="sm" :disabled="revokingId !== null && revokingId !== session.id" :loading="revokingId === session.id" @click="endSession(session)">{{ session.is_current ? 'Выйти здесь' : 'Завершить' }}</NButton></li></ul><p v-else class="panel-state">Активных сессий нет.</p>
            </section>
          </template>
        </main>
      </div>
    </template>
  </div>
</template>

<style scoped>
.account-page{font-size:16px;color:rgb(var(--content));padding-bottom:56px}.account-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin:12px 0 30px}.account-eyebrow{font-size:11px;letter-spacing:.17em;font-weight:750;color:#9c7bc6}.account-heading h1{font-size:32px;line-height:1.2;letter-spacing:-.04em;font-weight:750;margin:8px 0 10px}.account-heading>div>p:last-child{font-size:15px;color:rgb(var(--content-muted))}.account-outline,.account-primary{display:inline-flex;align-items:center;justify-content:center;min-height:44px;border-radius:10px;padding:10px 17px;font-size:14px;font-weight:650;text-align:center}.account-outline{border:1px solid rgb(var(--line-strong));background:rgb(var(--surface))}.account-primary{background:#e8c777;color:#252032}.account-primary:hover{background:#f0d898}.account-outline:hover{background:rgb(var(--surface-2))}.account-layout{display:grid;grid-template-columns:230px minmax(0,1fr);gap:26px;align-items:start}.account-sidebar{position:sticky;top:88px;border:1px solid rgb(var(--line));background:rgb(var(--surface));border-radius:18px;overflow:hidden}.sidebar-profile{padding:24px 18px;display:flex;gap:12px;align-items:center;border-bottom:1px solid rgb(var(--line))}.account-avatar{display:grid;place-items:center;flex-shrink:0;width:44px;height:44px;border-radius:14px;background:linear-gradient(130deg,#6e3dec,#b08aca);color:white;font-weight:700}.sidebar-profile>div{min-width:0}.sidebar-profile strong{font-size:14px;display:block;overflow-wrap:anywhere}.sidebar-profile>div>span{display:block;font-size:12px;color:rgb(var(--content-muted));overflow:hidden;text-overflow:ellipsis;margin-top:4px}.account-nav{padding:12px;display:grid;gap:4px}.account-nav>a{display:flex;align-items:center;gap:12px;font-size:14px;padding:12px;border-radius:9px;color:rgb(var(--content-muted));white-space:nowrap}.account-nav>a>span{font-size:19px;width:22px;text-align:center;color:#a48abd}.account-nav>a:hover{background:rgb(var(--surface-2))}.account-nav>a.selected{background:#9975cd1c;color:rgb(var(--content));font-weight:650}.nav-divider{height:1px;background:rgb(var(--line));margin:9px 8px}.account-logout{font-size:13px;text-align:left;padding:20px 24px;border-top:1px solid rgb(var(--line));width:100%;color:rgb(var(--content-muted))}.account-main{min-width:0;display:grid;gap:22px}.outing-card{display:grid;grid-template-columns:minmax(0,1fr) 190px;gap:24px;border:1px solid #453452;min-height:310px;padding:34px;border-radius:20px;background:radial-gradient(ellipse at 95% 50%,#53356a,transparent 65%),#191725;color:white;overflow:hidden}.outing-copy{position:relative;z-index:1}.outing-card .account-eyebrow{color:#ddc48c}.outing-card h2{font-size:30px;font-weight:750;letter-spacing:-.03em;line-height:1.2;max-width:490px;margin:20px 0 14px}.outing-card p:not(.account-eyebrow){font-size:15px;line-height:1.7;color:#d4ccdf;max-width:470px}.outing-card p.outing-date{font-weight:600;color:#f3e9da}.outing-card .account-primary{margin-top:24px}.outing-secondary{display:inline-block;margin:14px;font-size:14px;text-decoration:underline;text-underline-offset:4px}.outing-art{display:grid;place-items:center}.decorative-ticket{width:150px;min-height:226px;padding:21px 15px;background:linear-gradient(145deg,#f4e5c4,#d1b58b);color:#4c354d;border-radius:14px;transform:rotate(11deg);box-shadow:-18px 20px 0 -6px #ac85c42b,0 20px 40px #0005;text-align:center;display:flex;flex-direction:column;align-items:center}.decorative-ticket>span{font-size:13px;font-weight:800;letter-spacing:.12em}.decorative-ticket>b{font-size:66px;line-height:1.2;margin-top:18px;font-weight:750}.decorative-ticket>small{font-size:11px;letter-spacing:.08em}.ticket-perforation{width:calc(100% + 30px);margin:20px 0 14px;border-bottom:2px dashed #89705477}.decorative-ticket i{font-style:normal;font-size:8px;letter-spacing:.1em}
.account-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.account-stats>a{display:flex;gap:14px;align-items:center;border:1px solid rgb(var(--line));border-radius:14px;background:rgb(var(--surface));padding:20px;transition:border-color .2s}.account-stats>a:hover{border-color:#a78ac1}.stat-icon{width:42px;height:42px;flex-shrink:0;display:grid;place-items:center;border-radius:12px;background:#a487ca12;color:#9c7bc6;font-size:25px}.stat-icon.heart{color:#c87b9b;background:#c87b9b10}.account-stats strong{font-size:27px;font-weight:700;line-height:1.2}.account-stats strong small{font-size:16px;font-weight:500;color:rgb(var(--content-muted))}.account-stats p{font-size:12px;line-height:1.6;margin-top:5px;color:rgb(var(--content-muted))}.account-overview-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:20px}.account-panel{padding:26px;background:rgb(var(--surface));border:1px solid rgb(var(--line));border-radius:17px;min-width:0}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:14px}.panel-heading h2{font-size:20px;font-weight:700;letter-spacing:-.02em}.panel-heading>a,.club-summary>a,.text-action{font-size:13px;font-weight:600;color:#9871c3;text-decoration:underline;text-underline-offset:4px}.panel-heading>a{flex-shrink:0}.panel-intro{font-size:14px;line-height:1.7;color:rgb(var(--content-muted));margin-top:10px}.panel-state{padding:30px 0;font-size:15px;color:rgb(var(--content-muted));line-height:1.7}.panel-state button{margin-top:12px}.level-pill{font-size:12px;color:#9a7ab6;border:1px solid #a68abc55;background:#a68abc10;border-radius:20px;padding:5px 11px}.account-medals{display:flex;gap:10px;justify-content:space-between;margin-top:23px}.account-medals>a{flex:1;min-width:0;text-align:center}.account-medals img{width:100%;max-width:100px;aspect-ratio:1;object-fit:contain;border-radius:50%;margin:auto}.account-medals span{display:block;font-size:12px;line-height:1.5;margin-top:9px}.account-medals small{display:block;font-size:11px;color:#388c70;margin-top:5px}.club-summary{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;border-top:1px solid rgb(var(--line));padding-top:18px;margin-top:22px;font-size:12px;color:rgb(var(--content-muted))}.account-help{display:flex;align-items:center;gap:18px;padding:24px;border:1px solid rgb(var(--line));border-radius:14px;background:#a78bc209}.help-symbol{width:44px;height:44px;flex-shrink:0;border-radius:50%;display:grid;place-items:center;background:#a487ca15;color:#9c7bc6;font-size:23px}.account-help>div{flex:1}.account-help h2{font-size:16px;font-weight:650}.account-help p{font-size:13px;line-height:1.7;color:rgb(var(--content-muted));margin-top:6px}.account-help>a{flex-shrink:0}.empty-account{text-align:center;padding:40px 10px}.empty-account>span{font-size:36px;color:#ad95c2}.empty-account h3{font-size:18px;font-weight:650;margin-top:12px}.empty-account p{font-size:14px;line-height:1.7;color:rgb(var(--content-muted));margin:12px auto 20px;max-width:350px}.account-alert{font-size:14px;line-height:1.7;padding:16px;border:1px solid #d68e6155;border-radius:12px;background:#d68e6110}.account-alert button{text-decoration:underline;font-weight:600;margin-left:8px}.order-hint{font-size:13px;color:rgb(var(--content-muted));margin:24px 0 4px}.account-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:1px solid rgb(var(--line));padding-top:22px;margin-top:10px}.account-pagination span{font-size:13px;color:rgb(var(--content-muted));text-align:center}
.settings-panel{padding:32px}.settings-symbol{width:48px;height:48px;display:grid;place-items:center;background:#a487ca12;border-radius:12px;font-size:27px;color:#a08ab5;flex-shrink:0}.account-form{max-width:620px;margin-top:28px;display:grid;gap:20px}.account-form fieldset{display:grid;gap:22px;min-width:0}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.form-actions{display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding-top:6px}.unsaved-label{font-size:12px;color:rgb(var(--content-muted))}.form-error{font-size:14px;color:#d34e6a}.form-success{font-size:14px;color:#348969}.settings-note{font-size:13px;line-height:1.8;color:rgb(var(--content-muted));padding:16px;background:rgb(var(--surface-2));border-radius:10px;margin-top:24px;max-width:620px}.account-form .settings-note{margin-top:0}.show-password{display:flex;align-items:center;gap:10px;font-size:14px;color:rgb(var(--content-muted));cursor:pointer}.show-password input{width:18px;height:18px;accent-color:#8455c2}.session-list{margin-top:20px}.session-list>li{display:flex;gap:14px;align-items:center;padding:20px 0}.session-list>li+li{border-top:1px solid rgb(var(--line))}.session-list>li>div{flex:1;min-width:0}.session-list h3{font-size:15px;font-weight:600;display:flex;align-items:center;flex-wrap:wrap;gap:8px}.session-list p,.session-list small{font-size:12px;line-height:1.7;color:rgb(var(--content-muted));display:block;margin-top:4px}.device-symbol{font-size:26px;color:#9e87b5}.current-session{font-size:11px;color:#3f9375;background:#4b98791a;padding:3px 7px;border-radius:20px;font-weight:500}.password-success{max-width:460px;margin:40px auto;text-align:center}.password-success h1{font-size:25px;font-weight:700;margin:16px 0}.password-success p{font-size:16px;line-height:1.7;color:rgb(var(--content-muted));margin-bottom:22px}.success-symbol{font-size:32px;color:#3f9375}.account-page a:focus-visible,.account-page button:focus-visible{outline:3px solid #a487ca;outline-offset:4px}
@media(max-width:1100px){.account-layout{grid-template-columns:200px minmax(0,1fr);gap:18px}.account-overview-grid{grid-template-columns:1fr}.account-stats>a{padding:16px 12px;gap:9px}.stat-icon{width:30px;height:34px;font-size:21px}.outing-card{padding:28px;grid-template-columns:minmax(0,1fr) 140px}.outing-card h2{font-size:27px}.decorative-ticket{width:125px;min-height:205px}.account-club .account-medals{max-width:390px}.account-help{flex-wrap:wrap}.account-help>a{margin-left:62px}}
@media(max-width:760px){.account-heading{margin-top:5px;margin-bottom:22px}.account-heading h1{font-size:28px}.account-heading>div>p:last-child{font-size:14px;line-height:1.6}.account-heading>.account-outline{display:none}.account-layout{grid-template-columns:1fr;gap:20px}.account-sidebar{position:static;border-radius:14px}.sidebar-profile{padding:16px}.account-nav{display:flex;overflow-x:auto;padding:10px;gap:4px;scrollbar-width:thin}.account-nav>a{flex-shrink:0;padding:10px 12px}.nav-divider{width:1px;height:30px;flex-shrink:0;margin:5px}.account-nav>a>span{display:none}.account-logout{padding:12px 20px;font-size:12px;text-align:right}.outing-card{min-height:280px;padding:25px;gap:10px;grid-template-columns:minmax(0,1fr) 95px}.outing-card h2{font-size:25px}.outing-card p:not(.account-eyebrow){font-size:14px}.decorative-ticket{width:96px;min-height:185px;padding:18px 10px}.decorative-ticket b{font-size:49px}.decorative-ticket>span{font-size:10px}.decorative-ticket>small{font-size:9px}.decorative-ticket i{font-size:7px}.ticket-perforation{width:calc(100% + 20px)}.account-stats{gap:9px}.account-stats>a{padding:15px 12px;align-items:start;flex-direction:column;gap:9px}.account-stats strong{font-size:25px}.account-stats p{font-size:12px;line-height:1.5}.account-panel,.settings-panel{padding:21px}.panel-heading h2{font-size:20px}.account-help{padding:20px;gap:13px}.account-help>a{margin-left:0;width:100%}.account-form{margin-top:24px}.form-grid{grid-template-columns:1fr;gap:22px}.settings-symbol{display:none}.session-list>li{flex-wrap:wrap}.session-list>li>div{flex-basis:75%}.session-list>li>button{margin-left:40px}.account-pagination{gap:8px}.account-pagination span{font-size:12px}}
@media(max-width:380px){.outing-card{grid-template-columns:1fr}.outing-art{display:none}.account-stats strong{font-size:22px}.account-stats>a{padding:14px 10px}.account-pagination{flex-wrap:wrap}.account-pagination span{order:3;flex-basis:100%}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
