<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NModal from '@/components/ui/NModal.vue'
import { useLoyaltyStore } from '@/stores/loyalty'
import { customerUser } from '@/lib/customer'
import { dateFull, money, plural } from '@/lib/format'
import { fetchReferral, type ReferralInfo } from '@/lib/referral'
import { fetchRecommendations, type RecommendedEvent } from '@/lib/recommendations'
import { achievementCollection, achievementImage, CATEGORY_LABEL, fetchClub, type Achievement, type AchievementCategory, type ClubSnapshot } from '@/lib/achievementsCatalog'

const loyalty = useLoyaltyStore()
const me = customerUser()
const snapshot = ref<ClubSnapshot | null>(null)
const loading = ref(true)
const error = ref(false)
const collection = computed(() => achievementCollection(snapshot.value))
const earned = computed(() => collection.value.filter(a => a.status === 'earned'))
const category = ref<AchievementCategory | 'all'>('all')
const status = ref('all')
const search = ref('')
const selected = ref<Achievement | null>(null)
const filtered = computed(() => collection.value.filter(a =>
  (category.value === 'all' || a.category === category.value) &&
  (status.value === 'all' || a.status === status.value) &&
  (a.hidden ? 'Секретная медаль' : a.name).toLocaleLowerCase('ru').includes(search.value.trim().toLocaleLowerCase('ru')),
))
const next = computed(() => collection.value.filter(a => a.status === 'progress' && a.progress)
  .sort((a, b) => (b.progress!.current / b.target) - (a.progress!.current / a.target))[0])
const levelPct = computed(() => {
  const level = loyalty.loyaltyLevel
  if (!level) return 0
  if (!level.next_level) return 100
  const span = level.next_level.min_paid_orders - level.min_paid_orders
  return span > 0 ? Math.max(0, Math.min(100, Math.round((level.paid_orders - level.min_paid_orders) / span * 100))) : 0
})
const earlyAccessLevel = computed(() => snapshot.value?.levels.find(l => l.slug === snapshot.value?.rules.early_access_min_level_slug)?.name)
const referral = ref<ReferralInfo | null>(null)
const referralError = ref(false)
const copied = ref(false)
const copyError = ref(false)
let copyTimer: ReturnType<typeof setTimeout> | undefined
const recommended = ref<RecommendedEvent[]>([])
function title(a: Achievement): string { return a.hidden ? 'Секретная медаль' : a.name }
function pct(a: Achievement): number { return a.progress ? Math.min(100, Math.round(a.progress.current / a.progress.target * 100)) : 0 }
function stateLabel(a: Achievement): string {
  if (a.status === 'unknown') return loading.value ? 'Загружаем прогресс…' : 'Прогресс недоступен'
  if (a.hidden) return 'Ещё не раскрыта'
  if (a.status === 'earned') return 'Получено'
  return a.progress?.current ? 'В процессе' : 'Впереди'
}
async function loadClub(): Promise<void> {
  if (!loading.value) loading.value = true
  error.value = false
  try { snapshot.value = await fetchClub() } catch { snapshot.value = null; error.value = true }
  finally { loading.value = false }
}
async function loadReferral(): Promise<void> {
  referralError.value = false
  try { referral.value = await fetchReferral() } catch { referralError.value = true }
}
async function copyLink(): Promise<void> {
  if (!referral.value?.enabled) return
  copyError.value = false
  try {
    await navigator.clipboard.writeText(referral.value.link)
    copied.value = true
    clearTimeout(copyTimer)
    copyTimer = setTimeout(() => { copied.value = false }, 2500)
  } catch { copyError.value = true }
}
onMounted(() => {
  void loyalty.load()
  void loadClub()
  void loadReferral()
  void fetchRecommendations().then(rows => { recommended.value = rows }).catch(() => {})
})
onUnmounted(() => clearTimeout(copyTimer))
</script>

<template>
  <div class="club-page mx-auto max-w-content px-4 py-6 sm:px-6">
    <section class="club-hero" aria-labelledby="club-title">
      <div class="hero-copy">
        <p class="club-eyebrow">NABILET · КЛУБ ЗРИТЕЛЕЙ</p>
        <h1 id="club-title">Впечатления становятся<br><em>вашей коллекцией.</em></h1>
        <p class="hero-description">Ходите на любимые события, открывайте новые имена и собирайте медали за вечера, которые хочется помнить.</p>
        <div class="hero-actions">
          <a href="#collection" class="club-primary">Открыть коллекцию</a>
          <a href="#club-rules" class="club-text-link">Как устроен клуб</a>
        </div>
        <p class="hero-welcome">{{ me?.first_name ? me.first_name + ', рады видеть вас в клубе' : 'Ваша история начинается с первого выхода' }}</p>
      </div>
      <div class="hero-medals" aria-hidden="true">
        <img class="hero-medal hero-rock" :src="achievementImage('rock-alive')" alt="" width="240" height="240">
        <img class="hero-medal hero-legend" :src="achievementImage('legend')" alt="" width="300" height="300">
        <img class="hero-medal hero-theatre" :src="achievementImage('theatregoer')" alt="" width="200" height="200">
        <span class="hero-caption">15 МЕДАЛЕЙ · ВАША ИСТОРИЯ</span>
      </div>
    </section>

    <section class="club-dashboard" aria-label="Ваш прогресс в клубе">
      <div class="dashboard-level">
        <p class="club-label">Ваш уровень</p>
        <div class="dashboard-value">{{ loyalty.loyaltyLevel?.name ?? '—' }} <span class="level-star">✦</span></div>
        <div class="club-progress" role="progressbar" aria-label="До следующего уровня" :aria-valuenow="levelPct" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: levelPct + '%' }" /></div>
        <p v-if="loyalty.loyaltyLevel?.next_level" class="club-caption">Ещё {{ loyalty.loyaltyLevel.orders_to_next }} {{ plural(loyalty.loyaltyLevel.orders_to_next, 'заказ', 'заказа', 'заказов') }} до уровня «{{ loyalty.loyaltyLevel.next_level.name }}»</p>
        <p v-else-if="loyalty.loyaltyLevel" class="club-caption">Вы достигли высшего уровня клуба</p>
        <p v-else class="club-caption">{{ loyalty.loading ? 'Загружаем уровень…' : 'Уровень недоступен' }} <button v-if="!loyalty.loading" class="inline-link" @click="loyalty.load(true)">Повторить</button></p>
      </div>
      <RouterLink to="/bonus" class="dashboard-stat"><span class="club-label">Бонусный счёт</span><strong>{{ loyalty.bonus === null ? '—' : loyalty.bonusesCount.toLocaleString('ru-RU') }} <span>Б</span></strong><span class="club-caption">1 бонус = 1 ₽ · История бонусов</span></RouterLink>
      <div class="dashboard-stat"><span class="club-label">Посещено событий</span><strong>{{ snapshot?.visits ?? '—' }}</strong><span class="club-caption">По использованным билетам</span></div>
      <div class="dashboard-stat"><span class="club-label">Медали в коллекции</span><strong>{{ snapshot ? earned.length : '—' }} <span>/ {{ collection.length }}</span></strong><span class="club-caption">У каждого вечера есть продолжение</span></div>
    </section>

    <section v-if="next" class="next-achievement">
      <img :src="achievementImage(next.id)" alt="" width="80" height="80">
      <div><p class="club-label">Ваша ближайшая медаль</p><h2>{{ next.name }}</h2><p>{{ next.description }} Уже {{ next.progress?.current }} из {{ next.target }}.</p></div>
      <button class="club-outline" @click="selected = next">Посмотреть условия</button>
    </section>

    <section id="collection" class="collection-section" aria-labelledby="collection-title">
      <div class="section-heading"><div><p class="club-kicker">ВЕЧЕРА ПРОХОДЯТ. МЕДАЛИ ОСТАЮТСЯ.</p><h2 id="collection-title">Моя коллекция</h2><p>От первого билета до легенды зала. Найдите свою следующую цель.</p></div><span class="collection-count">{{ collection.length }} медалей</span></div>
      <div class="collection-tools">
        <div class="category-tabs" role="group" aria-label="Категория медалей">
          <button :class="{ active: category === 'all' }" :aria-pressed="category === 'all'" @click="category = 'all'">Все медали</button>
          <button v-for="(label, key) in CATEGORY_LABEL" :key="key" :class="{ active: category === key }" :aria-pressed="category === key" @click="category = key">{{ label }}</button>
        </div>
        <div class="collection-inputs"><label class="sr-only" for="medal-search">Поиск медали</label><input id="medal-search" v-model="search" type="search" placeholder="Найти медаль"><label class="sr-only" for="medal-status">Статус медали</label><select id="medal-status" v-model="status"><option value="all">Все статусы</option><option value="earned">Полученные</option><option value="progress">Впереди и в процессе</option><option value="hidden">Секретные</option></select></div>
      </div>
      <div v-if="error" class="club-notice" role="alert">Не удалось загрузить прогресс. Вы можете рассмотреть медали; ваши достижения появятся после загрузки. <button class="inline-link" @click="loadClub">Повторить</button></div>
      <p class="sr-only" aria-live="polite">Найдено медалей: {{ filtered.length }}</p>
      <div class="achievement-grid" :aria-busy="loading">
        <button v-for="a in filtered" :key="a.id" type="button" class="achievement-card" :class="[{ 'is-earned': a.status === 'earned', 'is-secret': a.hidden }, 'category-' + a.category]" :aria-label="title(a) + '. ' + stateLabel(a) + '. Открыть условия'" @click="selected = a">
          <div class="achievement-art">
            <span class="rarity" :class="{ legendary: a.rarity === 'Легендарная' }">{{ a.rarity }}</span>
            <div v-if="a.hidden" class="secret-art" aria-hidden="true"><span>✦</span><b>?</b></div>
            <img v-else :src="achievementImage(a.id)" alt="" width="280" height="280" loading="lazy">
            <span v-if="a.status === 'earned'" class="earned-seal">✓ Получено</span>
          </div>
          <div class="achievement-content">
            <p class="achievement-category">{{ CATEGORY_LABEL[a.category] }}</p>
            <h3>{{ title(a) }}</h3>
            <p class="achievement-description">{{ a.hidden ? 'Некоторые вечера открывают нечто особенное. Подсказка: город уже зажёг огни.' : a.description }}</p>
            <div class="achievement-bottom">
              <template v-if="a.progress && !a.hidden"><div class="achievement-progress-label"><span>{{ stateLabel(a) }}</span><span>{{ a.progress.current }} / {{ a.progress.target }}</span></div><div class="club-progress"><span :style="{ width: pct(a) + '%' }" /></div></template>
              <p v-else class="club-caption">{{ stateLabel(a) }}</p>
            </div>
          </div>
        </button>
      </div>
      <div v-if="!filtered.length" class="collection-empty"><h3>{{ status === 'earned' ? 'Ваша первая медаль ещё впереди' : 'Таких медалей не нашлось' }}</h3><p>Измените фильтры, чтобы посмотреть всю коллекцию.</p><button class="club-outline" @click="category = 'all'; status = 'all'; search = ''">Показать все медали</button></div>
      <p class="collection-footnote">Посещение засчитывается после прохода по билету из вашего заказа. Несколько билетов на один сеанс дают одно посещение. Жанр и артист учитываются, если они указаны в карточке события.</p>
    </section>

    <section id="club-rules" class="rules-section" aria-labelledby="rules-title">
      <div class="section-heading"><div><p class="club-kicker">БОЛЬШЕ ПОВОДОВ ВСТРЕТИТЬСЯ</p><h2 id="rules-title">Клуб, в котором всё понятно</h2><p>Медали — за впечатления. Уровень и бонусы — за оплаченные заказы.</p></div><RouterLink to="/bonus" class="inline-link">Мой бонусный счёт</RouterLink></div>
      <div v-if="snapshot" class="benefits-grid">
        <article><span class="benefit-number">{{ snapshot.rules.accrual_percent }}<small>%</small></span><h3>Возвращаются бонусами</h3><p>От суммы оплаченного заказа. Бонусы начисляются автоматически после оплаты.</p></article>
        <article><span class="benefit-number"><small>до </small>{{ snapshot.rules.spend_cap_percent }}<small>%</small></span><h3>Можно оплатить бонусами</h3><p>От стоимости билетов до скидок, в пределах остатка к оплате и вашего баланса. Выберите списание при покупке.</p></article>
        <article><span class="benefit-number">1 <small>к</small> 1</span><h3>Один бонус — один рубль</h3><p>Баланс и все начисления доступны в истории. При возврате бонусы пересчитываются.</p></article>
      </div>
      <div v-if="snapshot" class="levels-track">
        <article v-for="tier in snapshot.levels" :key="tier.slug" :class="{ current: loyalty.loyaltyLevel?.slug === tier.slug }"><span class="club-label">{{ loyalty.loyaltyLevel?.slug === tier.slug ? 'Ваш уровень' : 'Уровень клуба' }}</span><h3>{{ tier.name }}</h3><p>{{ tier.min_paid_orders === 0 ? 'Сразу после регистрации' : 'От ' + tier.min_paid_orders + ' оплаченных заказов' }}</p><span v-if="snapshot.rules.early_access_enabled && tier.slug === snapshot.rules.early_access_min_level_slug" class="level-perk">Ранний доступ начинается здесь</span></article>
      </div>
      <p v-if="snapshot?.rules.early_access_enabled" class="club-notice">Участникам уровня «{{ earlyAccessLevel }}» и выше доступна покупка на {{ snapshot.rules.early_access_hours }} ч раньше общего старта на события с ранними продажами.</p>
      <div class="club-faq">
        <details><summary>Чем медаль отличается от уровня?</summary><p>Медали отмечают посещения и ваши интересы. Уровень зависит от количества оплаченных заказов: один заказ с несколькими билетами считается один раз. Коллекционная медаль сама по себе не начисляет бонусы, не меняет уровень и не даёт скидку.</p></details>
        <details><summary>Когда появится прогресс?</summary><p>После того как билет отметят на входе, обновите страницу клуба. Если контролёр работал без интернета, дождитесь синхронизации. Учитываются использованные билеты из ваших оплаченных или частично возвращённых заказов; отменённые, отозванные и возвращённые билеты не учитываются.</p></details>
        <details><summary>Как считаются жанры и особые медали?</summary><p>Музыкальный жанр берётся из категории события или профиля артиста. Для театра нужны категории «комедия», «драма» и «мюзикл». Марафон — три сеанса в одном календарном месяце по времени площадки; прогресс показывает лучший месяц. Все медали можно получить один раз в коллекцию.</p></details>
        <details><summary>Что происходит при возврате?</summary><p>Баланс бонусов и число оплаченных заказов пересчитываются по правилам возврата. Прогресс медалей также пересчитывается: билет с возвратом больше не подтверждает посещение.</p></details>
      </div>
    </section>

    <section v-if="referral" class="referral-panel" aria-labelledby="referral-title">
      <div><p class="club-kicker">ЛУЧШИЕ ВЕЧЕРА — ВМЕСТЕ</p><h2 id="referral-title">Пригласите своих</h2><p v-if="referral.enabled">Друг получит {{ money(referral.invited_bonus) }} бонусами за первый оплаченный заказ, а вы — {{ money(referral.inviter_bonus) }}. Один друг — одна награда.</p><p v-else>Приглашения временно приостановлены. Уже начисленные бонусы остаются на вашем счёте.</p></div>
      <div v-if="referral.enabled" class="referral-controls"><label for="referral-link" class="club-label">Ваша ссылка для друга</label><div><input id="referral-link" :value="referral.link" readonly @focus="($event.target as HTMLInputElement).select()"><NButton @click="copyLink">{{ copied ? 'Скопировано' : 'Копировать' }}</NButton></div><p v-if="copyError" role="status">Не удалось скопировать. Выделите ссылку и скопируйте вручную.</p><p v-else aria-live="polite">{{ copied ? 'Ссылка скопирована — отправьте её другу.' : 'Приглашено: ' + referral.stats.invited + ' · Получили награду: ' + referral.stats.rewarded }}</p></div>
    </section>
    <p v-else-if="referralError" class="club-notice">Приглашения не загрузились. <button class="inline-link" @click="loadReferral">Повторить</button></p>

    <section v-if="recommended.length" class="recommendations-section"><div class="section-heading"><div><h2>Следующий вечер — ваш</h2><p>События по вашим интересам.</p></div></div><div class="recommendations-grid"><RouterLink v-for="event in recommended.filter(e => e.slug)" :key="event.id" :to="'/event/' + event.slug" class="recommendation"><img v-if="event.poster" :src="event.poster" alt="" loading="lazy"><div><h3>{{ event.title }}</h3><p>{{ event.starts_at && !Number.isNaN(Date.parse(event.starts_at)) ? dateFull(event.starts_at) : 'Расписание уточняется' }}</p></div></RouterLink></div></section>
    <section class="club-bottom"><div><h2>Какой будет ваша следующая история?</h2><p>Выберите событие, ради которого хочется выйти из дома.</p></div><div class="hero-actions"><RouterLink to="/" class="club-primary">Выбрать событие</RouterLink><RouterLink to="/my-raffles" class="inline-link">Мои розыгрыши</RouterLink></div></section>

    <NModal :open="selected !== null" :title="selected ? title(selected) : ''" @update:open="v => { if (!v) selected = null }">
      <template v-if="selected">
        <div class="medal-detail-art"><div v-if="selected.hidden" class="secret-art"><b>?</b></div><img v-else :src="achievementImage(selected.id)" alt="" width="260" height="260"></div>
        <p class="detail-rarity">{{ selected.rarity }} · {{ CATEGORY_LABEL[selected.category] }}</p>
        <p class="detail-story">{{ selected.hidden ? 'Ищите события, которые начинаются, когда город уже зажёг огни.' : selected.story }}</p>
        <div class="detail-rule"><h3>{{ selected.hidden ? 'Подсказка' : 'Как получить' }}</h3><p>{{ selected.hidden ? 'Иногда самое интересное происходит поздним вечером.' : selected.description }}</p></div>
        <div v-if="selected.progress && !selected.hidden" class="detail-rule"><div class="achievement-progress-label"><span>{{ stateLabel(selected) }}</span><span>{{ selected.progress.current }} / {{ selected.target }} {{ selected.unit }}</span></div><div class="club-progress"><span :style="{ width: pct(selected) + '%' }" /></div></div>
        <p v-if="selected.earnedAt" class="club-caption">Получено {{ dateFull(selected.earnedAt) }}</p>
        <p v-if="!selected.hidden" class="detail-reward">{{ selected.reward }}. Сохранится в вашей коллекции, пока посещения соответствуют условиям.</p>
      </template>
      <template #footer><NButton variant="secondary" @click="selected = null">К коллекции</NButton><RouterLink to="/" class="club-primary" @click="selected = null">Открыть афишу</RouterLink></template>
    </NModal>
  </div>
</template>

<style scoped>
.club-page{--club-gold:#e8c777;--club-ink:#111322;font-size:16px}
.club-hero{position:relative;display:grid;grid-template-columns:1.15fr 1fr;min-height:400px;padding:48px;border:1px solid #383348;border-radius:24px;overflow:hidden;background:radial-gradient(ellipse at 80% 45%,#47305c 0,transparent 55%),linear-gradient(120deg,#141521,#211b32);color:#fff}
.hero-copy{position:relative;z-index:2}.club-eyebrow,.club-kicker{font-size:12px;font-weight:700;letter-spacing:.15em}.club-eyebrow{color:var(--club-gold)}.club-hero h1{font-size:clamp(30px,3.1vw,46px);line-height:1.16;font-weight:750;letter-spacing:-.04em;margin-top:24px}.club-hero em{font-style:normal;color:var(--club-gold)}.hero-description{max-width:450px;color:#d0cbdc;line-height:1.7;margin-top:20px}.hero-actions{display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin-top:26px}.club-primary{display:inline-flex;align-items:center;justify-content:center;padding:12px 19px;border-radius:10px;background:var(--club-gold);color:#232030;font-size:14px;font-weight:700;transition:background .2s}.club-primary:hover{background:#f3dba2}.club-text-link{font-size:14px;color:#e9e3f1;text-decoration:underline;text-underline-offset:5px}.hero-welcome{font-size:13px;color:#b6aec6;margin-top:24px}.hero-medals{position:relative;min-width:0}.hero-medal{position:absolute;object-fit:contain;border-radius:50%;box-shadow:0 22px 50px #0008}.hero-legend{width:70%;right:3%;top:3%;transform:rotate(8deg)}.hero-rock{width:53%;left:0;top:0;transform:rotate(-14deg);z-index:1}.hero-theatre{width:44%;right:27%;bottom:3%;transform:rotate(-5deg);z-index:2}.hero-caption{position:absolute;bottom:-22px;right:10%;font-size:11px;letter-spacing:.18em;color:#c6b9d0}
.club-dashboard{display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;border:1px solid rgb(var(--line)/1);border-radius:16px;margin-top:20px;background:rgb(var(--surface)/1);overflow:hidden}.dashboard-level,.dashboard-stat{padding:24px}.dashboard-stat{display:flex;flex-direction:column;border-left:1px solid rgb(var(--line)/1)}.club-label{font-size:13px;color:rgb(var(--content-muted)/1)}.dashboard-value{font-size:26px;font-weight:750;margin:7px 0 12px}.level-star{color:#ab8941;float:right}.dashboard-stat strong{font-size:32px;line-height:1.2;margin:12px 0 8px;font-weight:750;letter-spacing:-.03em}.dashboard-stat strong span{font-size:18px;font-weight:500;color:rgb(var(--content-muted)/1)}.club-caption{font-size:13px;color:rgb(var(--content-muted)/1);line-height:1.6}.club-progress{height:5px;border-radius:20px;background:#8882;overflow:hidden}.club-progress span{display:block;height:100%;background:linear-gradient(90deg,#9270dd,#c49be8);border-radius:20px}.dashboard-level .club-caption{margin-top:8px}
.next-achievement{display:flex;align-items:center;gap:20px;padding:18px 24px;border:1px solid #a18bc540;border-radius:14px;margin-top:18px;background:#a18bc50a}.next-achievement img{border-radius:14px;flex-shrink:0}.next-achievement h2{font-size:19px;font-weight:700;margin:3px 0}.next-achievement p:last-child{font-size:14px;color:rgb(var(--content-muted)/1)}.next-achievement .club-outline{margin-left:auto;flex-shrink:0}.club-outline{border:1px solid #9e8fb966;border-radius:9px;padding:10px 16px;font-size:14px;font-weight:600}.club-outline:hover{background:#a18bc514}.inline-link{font-size:14px;color:rgb(var(--content)/1);text-decoration:underline;text-underline-offset:4px}
.collection-section,.rules-section,.recommendations-section{margin-top:52px;scroll-margin-top:90px}.section-heading{display:flex;align-items:end;justify-content:space-between;gap:20px}.club-kicker{color:#9e80bd;margin-bottom:10px}.section-heading h2,.referral-panel h2,.club-bottom h2{font-size:29px;line-height:1.2;font-weight:750;letter-spacing:-.025em}.section-heading p:not(.club-kicker){font-size:16px;line-height:1.6;color:rgb(var(--content-muted)/1);margin-top:10px}.collection-count{font-size:14px;white-space:nowrap;color:rgb(var(--content-muted)/1);padding:8px 12px;border:1px solid #8883;border-radius:30px}.collection-tools{margin:25px 0;display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between}.category-tabs{display:flex;flex-wrap:wrap;gap:6px}.category-tabs button{font-size:14px;padding:9px 13px;border-radius:9px;color:rgb(var(--content-muted)/1);border:1px solid transparent}.category-tabs button.active{background:#a18bc51a;border-color:#a18bc555;color:rgb(var(--content)/1)}.collection-inputs{display:flex;gap:8px}.collection-inputs input,.collection-inputs select{background:rgb(var(--surface)/1);color:inherit;border:1px solid #8884;border-radius:9px;padding:9px 12px;font-size:14px;min-width:0}.collection-inputs input{width:170px}.collection-inputs select{appearance:none;padding-right:30px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath d='m4 6 4 4 4-4' fill='none' stroke='%239b8baa' stroke-width='1.5'/%3E%3C/svg%3E");background-position:right 8px center;background-repeat:no-repeat}
.achievement-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.achievement-card{text-align:left;display:flex;flex-direction:column;border:1px solid #8883;border-radius:16px;overflow:hidden;background:rgb(var(--surface)/1);transition:transform .2s,border-color .2s,box-shadow .2s}.achievement-card:hover{transform:translateY(-5px);border-color:#bca468;box-shadow:0 14px 30px #0001}.achievement-card:focus-visible,.club-page button:focus-visible,.club-page a:focus-visible{outline:3px solid #ab89dc;outline-offset:4px}.achievement-art{position:relative;background:#111322;aspect-ratio:1.15;overflow:hidden;display:grid;place-items:center}.achievement-art img{width:100%;height:100%;object-fit:contain;transition:transform .3s}.achievement-card:hover .achievement-art img{transform:scale(1.045)}.rarity{position:absolute;top:12px;left:12px;border:1px solid #ae98cf44;background:#191525dd;border-radius:20px;padding:4px 8px;font-size:11px;color:#d2c3e6;z-index:1}.rarity.legendary{color:#f2d491;border-color:#d2b36a66}.earned-seal{position:absolute;bottom:12px;right:12px;background:#123f32;color:#bcf5d6;border:1px solid #70c9a366;padding:4px 9px;border-radius:20px;font-size:12px}.achievement-content{padding:20px;display:flex;flex-direction:column;flex:1}.achievement-category{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#9675b4;font-weight:700}.category-music .achievement-category{color:#a080cf}.category-theatre .achievement-category{color:#c77895}.category-discovery .achievement-category{color:#4b9fbd}.category-season .achievement-category{color:#ac8744}.achievement-content h3{font-size:19px;font-weight:700;line-height:1.3;margin-top:7px;letter-spacing:-.015em}.achievement-description{font-size:14px;line-height:1.65;color:rgb(var(--content-muted)/1);margin-top:10px}.achievement-bottom{margin-top:auto;padding-top:22px}.achievement-progress-label{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:rgb(var(--content-muted)/1);margin-bottom:8px}.is-earned .club-progress span{background:#5bb990}.secret-art{position:relative;width:160px;height:160px;border:2px solid #8670a04d;border-radius:50%;display:grid;place-items:center;background:radial-gradient(circle,#342642,#171525);box-shadow:inset 0 0 0 8px #111322,inset 0 0 0 9px #6b587766}.secret-art b{font-family:Georgia,serif;font-size:70px;color:#b9a0cc;font-weight:400}.secret-art span{position:absolute;top:16px;color:#b9a0cc;font-size:16px}.collection-footnote{font-size:13px;line-height:1.7;color:rgb(var(--content-muted)/1);margin-top:22px;max-width:920px}.collection-empty{text-align:center;padding:60px 20px;border:1px dashed #8885;border-radius:16px}.collection-empty h3{font-size:22px;font-weight:600}.collection-empty p{margin:12px 0 20px}.club-notice{font-size:14px;line-height:1.7;padding:16px;border-radius:12px;background:#a18bc514;margin:18px 0}
.benefits-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:26px}.benefits-grid article{padding:28px;border:1px solid #8883;border-radius:16px;background:rgb(var(--surface)/1)}.benefit-number{font-size:48px;font-weight:650;letter-spacing:-.04em;color:#ac8c55}.benefit-number small{font-size:25px}.benefits-grid h3{font-size:18px;font-weight:700;margin:12px 0}.benefits-grid p,.levels-track p{font-size:14px;line-height:1.7;color:rgb(var(--content-muted)/1)}.levels-track{display:flex;flex-wrap:wrap;gap:14px;margin-top:18px}.levels-track article{flex:1;min-width:180px;border:1px solid #8883;border-radius:14px;padding:22px}.levels-track article.current{border-color:#ad8bc4;background:#a18bc50b}.levels-track h3{font-size:22px;font-weight:700;margin:7px 0}.level-perk{display:block;font-size:12px;color:#9675b4;margin-top:12px}.club-faq{margin-top:24px;border-top:1px solid #8883}.club-faq details{padding:20px 0;border-bottom:1px solid #8883}.club-faq summary{cursor:pointer;font-size:16px;font-weight:600}.club-faq p{font-size:15px;line-height:1.8;max-width:850px;margin-top:12px;color:rgb(var(--content-muted)/1)}
.referral-panel{display:grid;grid-template-columns:1fr 1fr;gap:36px;padding:32px;background:#a18bc510;border:1px solid #a18bc533;border-radius:20px;margin-top:44px}.referral-panel p:not(.club-kicker){font-size:14px;line-height:1.7;color:rgb(var(--content-muted)/1);margin-top:12px}.referral-controls{align-self:center;min-width:0}.referral-controls>div{display:flex;gap:8px;margin-top:8px}.referral-controls input{width:100%;min-width:0;background:rgb(var(--surface)/1);border:1px solid #8884;border-radius:9px;padding:12px;font-size:14px}.recommendations-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:22px}.recommendation{display:flex;align-items:center;gap:15px;padding:15px;border:1px solid #8883;border-radius:12px}.recommendation img{width:60px;height:80px;object-fit:cover;border-radius:6px}.recommendation h3{font-weight:600;font-size:16px}.recommendation p{font-size:13px;margin-top:5px}.club-bottom{display:flex;justify-content:space-between;align-items:center;gap:24px;padding:32px 0;margin-top:28px;border-top:1px solid #8883}.club-bottom h2{font-size:23px}.club-bottom p{font-size:14px;margin-top:9px;color:rgb(var(--content-muted)/1)}.club-bottom .hero-actions{margin:0}
.medal-detail-art{display:grid;place-items:center;background:#111322;border-radius:14px;min-height:200px}.medal-detail-art img{width:210px;height:210px;object-fit:contain}.detail-rarity{font-size:12px;text-align:center;color:#a589b9;margin:14px 0}.detail-story{font-size:16px;line-height:1.6;text-align:center}.detail-rule{margin-top:18px}.detail-rule h3{font-size:14px;font-weight:700;margin-bottom:6px}.detail-rule p,.detail-reward{font-size:14px;line-height:1.6;color:rgb(var(--content-muted)/1)}.detail-reward{padding:12px;background:#a18bc510;border-radius:10px;margin-top:18px}
@media(min-width:1280px){.achievement-grid{grid-template-columns:repeat(5,minmax(0,1fr))}.achievement-content{padding:17px}.achievement-content h3{font-size:18px}}
@media(max-width:1000px){.club-hero{padding:32px;grid-template-columns:1.3fr 1fr}.club-dashboard{grid-template-columns:repeat(2,1fr)}.dashboard-stat:nth-child(3){border-left:0;border-top:1px solid #8883}.dashboard-stat:nth-child(4){border-top:1px solid #8883}.achievement-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.hero-legend{top:20%}.hero-theatre{bottom:15%}.benefits-grid article{padding:20px}}
@media(max-width:640px){.club-hero{padding:28px 23px;grid-template-columns:1fr;min-height:0}.club-hero h1{font-size:34px}.hero-description{font-size:15px}.hero-medals{height:220px;margin-top:22px}.hero-legend{width:185px;top:0;right:12%}.hero-rock{width:145px;top:18px;left:3%}.hero-theatre{width:120px;bottom:0;right:22%}.hero-caption{bottom:-10px;font-size:9px;right:10%}.dashboard-level,.dashboard-stat{padding:18px}.dashboard-value{font-size:24px}.dashboard-stat strong{font-size:28px}.next-achievement{padding:15px;gap:12px;flex-wrap:wrap}.next-achievement img{width:62px;height:62px}.next-achievement>div{flex:1}.next-achievement .club-outline{margin-left:74px}.section-heading h2{font-size:26px}.section-heading{align-items:start}.collection-count{display:none}.collection-section,.rules-section{margin-top:38px}.category-tabs{gap:3px}.category-tabs button{padding:8px 10px}.collection-inputs{width:100%}.collection-inputs input{flex:1;width:0}.collection-inputs select{max-width:55%}.achievement-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.achievement-content{padding:14px}.achievement-content h3{font-size:17px}.achievement-description{font-size:14px;line-height:1.5}.rarity{font-size:10px;top:8px;left:8px;padding:3px 7px}.achievement-art{aspect-ratio:1}.secret-art{width:110px;height:110px}.secret-art b{font-size:48px}.benefits-grid{grid-template-columns:1fr}.benefits-grid article{padding:24px}.benefit-number{font-size:40px}.referral-panel{grid-template-columns:1fr;padding:24px;gap:20px}.recommendations-grid{grid-template-columns:1fr}.club-bottom{align-items:start;flex-direction:column}.club-kicker{font-size:10px}.hero-welcome{font-size:12px}}
@media(prefers-reduced-motion:reduce){.achievement-card,.achievement-art img,.club-primary{transition:none}.achievement-card:hover,.achievement-card:hover .achievement-art img{transform:none}}
</style>
