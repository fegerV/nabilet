<script setup lang="ts">
/**
 * Кабинет «Клуб зрителей» — экран «Моя коллекция» (бриф §4, §13).
 *
 * ЧТО ЗДЕСЬ И ПОЧЕМУ
 *   Бриф превращает историю посещений в «зрительскую биографию»: ачивки +
 *   бонусы + персональные предложения. Экран — не список значков, а коллекция
 *   медалей, которую приятно заполнять, и точка продажи: разблокировка ведёт к
 *   следующей покупке (§6).
 *
 * ДАННЫЕ
 *   Каталог ачивок — демо-константа (`lib/achievementsCatalog.ts`): бэкенда
 *   ачивок ещё нет. Баланс бонусов в шапке — РЕАЛЬНЫЙ, из `stores/loyalty`
 *   (тот же источник, что у чипа в шапке и плитки в кабинете). Посещения и
 *   уровень — демо-числа, повторяющие «демо-числа» брифа.
 *
 * АНИМАЦИЯ РАЗБЛОКИРОВКИ
 *   Отдельный момент (§4): медаль → поздравление → предложение применить бонус
 *   ИЛИ найти событие. Показываем один раз за сессию для свежеразблокированной
 *   ачивки, чтобы не дёргать покупателя при каждом заходе.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NModal from '@/components/ui/NModal.vue'
import { useLoyaltyStore } from '@/stores/loyalty'
import { useUiStore } from '@/stores/ui'
import { customerUser } from '@/lib/customer'
import { dateFull, money, plural, time } from '@/lib/format'
import { fetchReferral, type ReferralInfo } from '@/lib/referral'
import { fetchRecommendations, type RecommendedEvent } from '@/lib/recommendations'
import {
  ACHIEVEMENTS,
  CATEGORY_LABEL,
  CATEGORY_TONE,
  glyphs,
  type Achievement,
  type AchievementCategory,
} from '@/lib/achievementsCatalog'

const router = useRouter()
const loyalty = useLoyaltyStore()
const ui = useUiStore()
const me = customerUser()

const displayName = computed(() => {
  const name = [me?.first_name, me?.last_name].filter(Boolean).join(' ').trim()
  return name === '' ? me?.email ?? 'Зритель' : name
})

/* ── Уровень клуба: РЕАЛЬНЫЙ, из `/me/loyalty-level` (фаза 3 плана) ─────────
 * Демо-арифметика (бриф §4) заменена на данные сервера: уровень — производное
 * от числа оплаченных заказов, пересчитываемое обсервером при смене статуса
 * заказа. До загрузки показываем «Старт» и 100% заполнения, чтобы прогресс-бар
 * не дёргался на пустом состоянии. */
const level = computed(() => loyalty.loyaltyLevel)
const levelName = computed(() => level.value?.name ?? 'Старт')
const paidOrders = computed(() => level.value?.paid_orders ?? 0)
const levelNext = computed(() => level.value?.next_level ?? null)
const levelPct = computed(() => {
  const lv = level.value
  if (!lv || !lv.next_level) return 100
  return Math.min(100, Math.round((lv.paid_orders / lv.next_level.min_paid_orders) * 100))
})
const levelRemaining = computed(() => level.value?.orders_to_next ?? 0)

const earnedCount = computed(() => ACHIEVEMENTS.filter((a) => a.status === 'earned').length)
const totalCount = ACHIEVEMENTS.length

/* ── Медаль: классы по статусу ────────────────────────────────────────────── */
function medalClass(a: Achievement): string {
  if (a.status === 'earned') return 'bg-brand-gradient text-white shadow-brand'
  if (a.status === 'progress') return 'bg-brand-500/10 text-brand-300 ring-1 ring-brand-500/25'
  return 'bg-surface-3 text-subtle'
}

function progressPct(a: Achievement): number {
  if (!a.progress || a.progress.target === 0) return 0
  return Math.min(100, Math.round((a.progress.current / a.progress.target) * 100))
}

/** Статичный SVG из каталога (безопасен: строки принадлежат проекту). */
function glyphFor(key: string): string {
  return glyphs[key] ?? ''
}

/* ── Детальная карточка ачивки ───────────────────────────────────────────── */
const selected = ref<Achievement | null>(null)
function openDetail(a: Achievement): void {
  selected.value = a
}

/* ── Анимация разблокировки (показываем один раз за сессию) ─────────────── */
const celebrate = ref<Achievement | null>(null)
const CELEBRATE_KEY = 'nabilet_club_celebrated'

function maybeCelebrate(): void {
  const fresh = ACHIEVEMENTS.find((a) => a.celebrate && a.status === 'earned')
  if (!fresh) return
  if (sessionStorage.getItem(CELEBRATE_KEY) === fresh.id) return
  celebrate.value = fresh
  sessionStorage.setItem(CELEBRATE_KEY, fresh.id)
}

onMounted(() => {
  // Баланс в шапке — из реального стора (idempotent; грузится и оболочкой).
  void loyalty.load()
  maybeCelebrate()
  // Фаза 4: блок «Пригласите друга». Гость сюда не попадает (маршрут под
  // `requiresCustomer`), а `/me/referral` под `auth:api` — запрос идёт всегда.
  void loadReferral()
  // Фаза 5: персональная полка «Рекомендуем для вас». Тот же `auth:api`; у
  // нового аккаунта вернётся пустой список, и блок просто не покажется.
  void loadRecommendations()
})

function goCatalog(): void {
  router.push('/')
}
function goBonus(): void {
  router.push('/bonus')
}
function goRaffles(): void {
  router.push('/my-raffles')
}

/* ── «Пригласите друга» (фаза 4) ────────────────────────────────────────────
 * Всё содержимое блока — из `GET /me/referral`: ссылка, суммы наград и
 * статистика. Вёрстка не знает ни порогов, ни размеров наград: сервер их
 * единственный источник (настройки админки живут в `settings`, scope
 * `loyalty`), и «сколько бонусов» здесь показывается ровно столько, сколько
 * начислит сервер.
 *
 * ЗАЧЕМ СЧЁТЧИКИ, А НЕ ПРОСТО ССЫЛКА
 *   «Приглашено 3 · награждено 2 · заработано 600 ₽» — доказательство, что
 *   программа работает (бриф §6: социальный капитал растёт вместе с
 *   покупками). Без чисел ссылка воспринимается как «ещё одна акция», с
 *   числами — как история.
 * ────────────────────────────────────────────────────────────────────────── */
const referral = ref<ReferralInfo | null>(null)
const referralError = ref(false)
const copied = ref(false)
/** Поле ссылки: выделяем целину по фокусу — копировать проще, чем мышью. */
const linkInput = ref<HTMLInputElement | null>(null)

async function loadReferral(): Promise<void> {
  try {
    referral.value = await fetchReferral()
  } catch {
    // Экран клуба не должен падать из-за одного блока: без приглашений клуб
    // работает. Ссылку выдадут при следующем заходе, ошибка — не фатальна.
    referralError.value = true
  }
}

async function copyLink(): Promise<void> {
  const link = referral.value?.link
  if (!link) return

  try {
    await navigator.clipboard.writeText(link)
    copied.value = true
    ui.notify('mint', 'Ссылка скопирована', 'Отправьте её другу — бонусы начислим обеим сторонам.')
    window.setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch {
    // Буфер обмена доступен только по жесту и на https: показываем ссылку
    // как есть — выделить и скопировать руками пользователь сможет всегда.
    ui.notify('sun', 'Не удалось скопировать', 'Скопируйте ссылку вручную — она ниже.')
  }
}

/* ── «Рекомендуем для вас» (фаза 5) ────────────────────────────────────────
 * Персональная полка: сервер считает её из личной истории (избранное,
 * подписки, оплаченные заказы). Пустой список — это не ошибка, а «пока нечего
 * предложить»: у нового аккаунта сигналов ещё нет, и блок просто не рисуется.
 * Ошибка загрузки тоже не роняет клуб — рекомендации необязательны.
 * ────────────────────────────────────────────────────────────────────────── */
const recommended = ref<RecommendedEvent[]>([])

async function loadRecommendations(): Promise<void> {
  try {
    recommended.value = await fetchRecommendations()
  } catch {
    // Клуб работает и без полки: оставляем её пустой, а не показываем
    // «не удалось загрузить» там, где список и так необязателен.
    recommended.value = []
  }
}

/** Дата ближайшего сеанса или честное «уточняется» — битая дата не «1970». */
function recommendedStartsAt(iso: string | null): string {
  if (!iso || Number.isNaN(new Date(iso).getTime())) return 'Расписание уточняется'
  return `${dateFull(iso)}, ${time(iso)}`
}

/** Ссылка на витрину: без slug строить нечего (`/event/:slug`). */
function recommendedHref(event: RecommendedEvent): string | null {
  return event.slug ? `/event/${event.slug}` : null
}
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <!-- Шапка клуба: градиент «сцена», уровень, бонусы, прогресс -->
    <section
      class="relative overflow-hidden rounded-2xl bg-gradient-brand p-6 text-white shadow-lg sm:p-8"
      aria-label="Клуб зрителей"
    >
      <div class="absolute inset-0 bg-gradient-stage" aria-hidden="true" />
      <div class="relative">
        <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-white/70">Клуб зрителей</p>
        <h1 class="mt-1 font-display text-2xl font-bold tracking-tight sm:text-3xl">
          Мой NABILET
          <span class="font-normal opacity-80">· {{ displayName }}</span>
        </h1>

        <div class="mt-5 flex flex-wrap items-center gap-3">
          <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-sm font-semibold backdrop-blur">
            <span class="grid h-5 w-5 place-items-center rounded-full bg-white/25 text-2xs">★</span>
            {{ levelName }}
          </span>
          <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-sm font-semibold backdrop-blur">
            <span class="text-base leading-none">✦</span>
            {{ loyalty.bonusesCount }} {{ plural(loyalty.bonusesCount, 'бонус', 'бонуса', 'бонусов') }}
          </span>
          <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-sm font-semibold backdrop-blur">
            <span class="text-base leading-none">🎟</span>
            {{ paidOrders }} {{ plural(paidOrders, 'оплаченный заказ', 'оплаченных заказа', 'оплаченных заказов') }}
          </span>
        </div>

        <div class="mt-5 max-w-md">
          <div class="h-2 w-full overflow-hidden rounded-full bg-white/20">
            <div class="h-full rounded-full bg-white/90 transition-all duration-320 ease-out" :style="{ width: levelPct + '%' }" />
          </div>
          <p class="mt-1.5 text-2xs text-white/75">
            <template v-if="levelRemaining > 0">
              Ещё {{ levelRemaining }} {{ plural(levelRemaining, 'оплаченный заказ', 'оплаченных заказа', 'оплаченных заказов') }} — и вы на уровень «{{ levelNext?.name }}»
            </template>
            <template v-else>Максимальный уровень — вы легенда зала.</template>
          </p>
        </div>
      </div>
    </section>

    <!-- Прогресс клуба: три итоговые плитки -->
    <section class="mt-4 grid gap-3 sm:grid-cols-3">
      <div class="surface-card flex items-center gap-3 p-4">
        <span class="grid h-10 w-10 flex-none place-items-center rounded-lg bg-brand-500/10 text-lg text-brand-400">★</span>
        <div>
          <p class="text-lg font-bold tabular-nums text-content">{{ levelName }}</p>
          <p class="text-2xs text-subtle">уровень клуба</p>
        </div>
      </div>
      <div class="surface-card flex items-center gap-3 p-4">
        <span class="grid h-10 w-10 flex-none place-items-center rounded-lg bg-mint-500/10 text-lg text-mint-400">✦</span>
        <div>
          <p class="text-lg font-bold tabular-nums text-content">{{ loyalty.bonusesCount }}</p>
          <p class="text-2xs text-subtle">бонусов на счету</p>
        </div>
      </div>
      <div class="surface-card flex items-center gap-3 p-4">
        <span class="grid h-10 w-10 flex-none place-items-center rounded-lg bg-accent-500/10 text-lg text-accent-400">🏅</span>
        <div>
          <p class="text-lg font-bold tabular-nums text-content">{{ earnedCount }}<span class="text-content-subtle">/{{ totalCount }}</span></p>
          <p class="text-2xs text-subtle">медалей получено</p>
        </div>
      </div>
    </section>

    <!-- Моя коллекция: сетка медалей -->
    <section class="mt-8">
      <div class="flex items-end justify-between gap-3">
        <div>
          <h2 class="font-display text-xl font-bold tracking-tight text-content">Моя коллекция</h2>
          <p class="mt-1 text-sm text-muted">Каждый билет — шаг в вашей истории. Откройте их все.</p>
        </div>
        <NBadge :tone="'brand'" size="md">{{ earnedCount }} из {{ totalCount }}</NBadge>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article
          v-for="a in ACHIEVEMENTS"
          :key="a.id"
          class="surface-card group flex cursor-pointer flex-col gap-3 p-4 transition-all duration-120 ease-out hover:-translate-y-0.5 hover:shadow-md"
          role="button"
          tabindex="0"
          @click="openDetail(a)"
          @keydown.enter="openDetail(a)"
        >
          <div class="flex items-center gap-3">
            <span :class="medalClass(a)" class="grid h-14 w-14 flex-none place-items-center rounded-xl" aria-hidden="true">
              <svg viewBox="0 0 24 24" class="h-7 w-7" v-html="glyphFor(a.icon)" />
            </span>
            <div class="min-w-0 flex-1">
              <h3 class="truncate text-sm font-semibold text-content">{{ a.name }}</h3>
              <p class="mt-0.5 text-2xs text-subtle">{{ CATEGORY_LABEL[a.category as AchievementCategory] }}</p>
            </div>
            <span
              v-if="a.status === 'earned'"
              class="grid h-6 w-6 flex-none place-items-center rounded-full bg-mint-500/15 text-xs font-bold text-mint-400"
              aria-label="Получено"
            >✓</span>
            <span v-else-if="a.hidden" class="flex-none text-subtle" aria-hidden="true">🔒</span>
          </div>

          <p class="text-xs text-muted text-balance">
            {{ a.hidden ? 'Условие раскроется в процессе — эффект открытия.' : a.description }}
          </p>

          <div v-if="a.status === 'progress' && a.progress" class="mt-1">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-3">
              <div class="h-full rounded-full bg-brand-gradient transition-all duration-320 ease-out" :style="{ width: progressPct(a) + '%' }" />
            </div>
            <p class="mt-1.5 text-2xs tabular-nums text-subtle">
              {{ a.progress.current }} из {{ a.progress.target }}
            </p>
          </div>
          <p v-else-if="a.status === 'earned'" class="text-2xs font-medium text-mint-400">Получено</p>
          <p v-else class="text-2xs text-subtle">Скрытая ачивка</p>

          <p class="mt-auto text-2xs text-muted">🎁 {{ a.reward }}</p>
        </article>
      </div>
    </section>

    <!-- Петля удержания: мягкий CTA к следующей покупке -->
    <section class="mt-8 surface-card flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h3 class="text-sm font-semibold text-content">Закройте коллекцию — каждый билет ближе к новой медали</h3>
        <p class="mt-1 text-xs text-subtle">Бонусы за покупки идут в следующий заказ, а серии посещений открывают статусы и ранний доступ.</p>
      </div>
      <NButton variant="accent" icon="→" @click="goCatalog">Найти событие</NButton>
    </section>

    <!-- Пригласите друга (фаза 4): ссылка, награды, статистика -->
    <section v-if="referral" class="mt-8" aria-label="Пригласите друга">
      <div class="flex items-end justify-between gap-3">
        <div>
          <h2 class="font-display text-xl font-bold tracking-tight text-content">Пригласите друга</h2>
          <p class="mt-1 text-sm text-muted">
            Друг получает {{ money(referral.invited_bonus) }} бонусами за первый оплаченный заказ,
            вы — {{ money(referral.inviter_bonus) }} за приведённого друга.
          </p>
        </div>
        <NBadge v-if="!referral.enabled" tone="sun" size="md">программа выключена</NBadge>
      </div>

      <div class="surface-card mt-4 p-4 sm:p-5">
        <label class="block text-2xs font-medium text-subtle" for="referral-link">Ваша ссылка</label>
        <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
          <input
            id="referral-link"
            ref="linkInput"
            :value="referral.link"
            readonly
            class="h-11 flex-1 rounded-lg border border-line bg-surface-2 px-3 font-mono text-sm text-content focus:outline-none"
            @focus="linkInput?.select()"
          />
          <NButton variant="primary" icon="⧉" @click="copyLink">
            {{ copied ? 'Скопировано' : 'Скопировать' }}
          </NButton>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
          <div class="rounded-lg bg-surface-2 px-3 py-2.5">
            <dt class="text-2xs text-subtle">приглашено друзей</dt>
            <dd class="mt-0.5 text-lg font-bold tabular-nums text-content">{{ referral.stats.invited }}</dd>
          </div>
          <div class="rounded-lg bg-surface-2 px-3 py-2.5">
            <dt class="text-2xs text-subtle">оплатили первый заказ</dt>
            <dd class="mt-0.5 text-lg font-bold tabular-nums text-content">{{ referral.stats.rewarded }}</dd>
          </div>
          <div class="rounded-lg bg-surface-2 px-3 py-2.5">
            <dt class="text-2xs text-subtle">заработано бонусами</dt>
            <dd class="mt-0.5 text-lg font-bold tabular-nums text-mint-400">{{ money(referral.stats.earned) }}</dd>
          </div>
        </dl>

        <p class="mt-3 text-xs text-subtle">
          Награда приходит после первой оплаты друга — бонусами на счёт, по тем же правилам, что и
          кэшбэк. Один друг приносит награду один раз.
        </p>
        <p v-if="!referral.enabled" class="mt-1.5 text-xs text-sun-400">
          Программа выключена в настройках клуба: по новым приглашениям награда не начислится, пока
          её не включат снова. Уже начисленные бонусы остаются на счету.
        </p>
      </div>
    </section>

    <!-- Блок приглашений не загрузился: клуб работает и без него, но молчать
         нельзя — иначе ссылка «пропала» без объяснения. -->
    <p v-else-if="referralError" class="mt-8 text-xs text-subtle">
      Не удалось загрузить приглашения — обновите страницу.
    </p>

    <!-- Розыгрыши «счастливый билет» (фаза 5) -->
    <section v-if="me" class="mt-8" aria-label="Розыгрыши">
      <div class="flex items-end justify-between gap-3">
        <div>
          <h2 class="font-display text-xl font-bold tracking-tight text-content">Розыгрыши</h2>
          <p class="mt-1 text-sm text-muted">
            Каждый оплаченный заказ из окна розыгрыша стал билетом — призы разыгрывает организатор.
          </p>
        </div>
      </div>

      <div class="surface-card mt-4 flex flex-col items-start gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">
          Посмотрите, в каких розыгрышах участвуют ваши заказы и не стал ли один из них счастливым.
        </p>
        <NButton variant="secondary" @click="goRaffles">Мои розыгрыши</NButton>
      </div>
    </section>

    <!-- «Рекомендуем для вас» (фаза 5): персональная полка. Пусто — не рисуем:
         у нового аккаунта нет ни одного сигнала, и заголовок с пустотой был бы
         обещанием, которого нет. -->
    <section v-if="recommended.length" class="mt-8" aria-label="Рекомендуем для вас">
      <div class="flex items-end justify-between gap-3">
        <div>
          <h2 class="font-display text-xl font-bold tracking-tight text-content">Рекомендуем для вас</h2>
          <p class="mt-1 text-sm text-muted">
            Подобрали по тому, что вам уже интересно: избранное, подписки и покупки.
          </p>
        </div>
      </div>

      <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li
          v-for="event in recommended"
          :key="event.id"
          class="surface-card flex items-center gap-3 p-3"
        >
          <RouterLink
            v-if="recommendedHref(event)"
            :to="recommendedHref(event)!"
            class="h-16 w-12 flex-none overflow-hidden rounded-lg"
            :aria-label="`Открыть ${event.title}`"
          >
            <img
              v-if="event.poster"
              :src="event.poster"
              alt=""
              class="h-full w-full object-cover"
            />
            <span
              v-else
              class="grid h-full w-full place-items-center bg-brand-gradient text-lg text-brand-on"
              aria-hidden="true"
            >{{ event.title.slice(0, 1) }}</span>
          </RouterLink>
          <span
            v-else
            class="grid h-16 w-12 flex-none place-items-center rounded-lg bg-surface-3 text-lg text-subtle"
            aria-hidden="true"
          >♪</span>

          <div class="min-w-0 flex-1">
            <RouterLink
              v-if="recommendedHref(event)"
              :to="recommendedHref(event)!"
              class="block truncate text-sm font-medium text-content transition-colors hover:text-brand-400"
            >{{ event.title }}</RouterLink>
            <span v-else class="block truncate text-sm font-medium text-content">{{ event.title }}</span>
            <p class="mt-0.5 text-xs text-subtle">{{ recommendedStartsAt(event.starts_at) }}</p>
          </div>
        </li>
      </ul>
    </section>

    <!-- Детальная карточка ачивки -->
    <NModal :open="selected !== null" :title="selected?.name ?? ''" @update:open="(v: boolean) => (selected = v ? selected : null)">
      <template v-if="selected">
        <div class="flex items-center gap-3">
          <span :class="medalClass(selected)" class="grid h-14 w-14 flex-none place-items-center rounded-xl" aria-hidden="true">
            <svg viewBox="0 0 24 24" class="h-7 w-7" v-html="glyphFor(selected.icon)" />
          </span>
          <div>
            <NBadge :tone="CATEGORY_TONE[selected.category as AchievementCategory]">{{ CATEGORY_LABEL[selected.category as AchievementCategory] }}</NBadge>
            <p class="mt-1.5 text-xs text-muted">{{ selected.hidden ? 'Скрытая ачивка' : selected.description }}</p>
          </div>
        </div>

        <div v-if="selected.status === 'progress' && selected.progress" class="mt-4">
          <div class="h-2 w-full overflow-hidden rounded-full bg-surface-3">
            <div class="h-full rounded-full bg-brand-gradient" :style="{ width: progressPct(selected) + '%' }" />
          </div>
          <p class="mt-1.5 text-xs text-subtle tabular-nums">
            {{ selected.progress.current }} из {{ selected.progress.target }} ·
            ещё {{ selected.progress.target - selected.progress.current }} {{ plural(selected.progress.target - selected.progress.current, 'событие', 'события', 'событий') }} до награды
          </p>
        </div>

        <div v-else-if="selected.status === 'earned'" class="mt-4 flex items-center gap-2 text-sm font-medium text-mint-400">
          <span aria-hidden="true">✓</span> Получено
          <span v-if="selected.earnedAt" class="font-normal text-subtle">· {{ selected.earnedAt }}</span>
        </div>

        <p class="mt-4 rounded-lg bg-surface-2 px-3 py-2.5 text-xs text-muted">
          <span class="mr-1" aria-hidden="true">🎁</span> Награда: {{ selected.reward }}
        </p>
      </template>

      <template #footer>
        <NButton v-if="selected?.status === 'progress'" variant="primary" block @click="goCatalog">Найти подходящие события</NButton>
        <NButton v-else-if="selected?.status === 'earned'" variant="primary" block @click="goBonus">История бонусов</NButton>
        <NButton v-else variant="secondary" block @click="goCatalog">Смотреть афишу</NButton>
      </template>
    </NModal>

    <!-- Анимация разблокировки (точка продажи, бриф §4/§6) -->
    <NModal :open="celebrate !== null" title="Новая медаль!" size="sm" @update:open="(v: boolean) => (celebrate = v ? celebrate : null)">
      <template v-if="celebrate">
        <div class="flex flex-col items-center text-center">
          <span class="relative grid h-24 w-24 animate-pulse-ring place-items-center rounded-2xl bg-brand-gradient text-white shadow-brand">
            <svg viewBox="0 0 24 24" class="h-12 w-12 animate-scale-in" v-html="glyphFor(celebrate.icon)" />
          </span>
          <h3 class="mt-4 font-display text-lg font-bold text-content">{{ celebrate.name }}</h3>
          <p class="mt-1 text-sm text-muted">{{ celebrate.description }}</p>
          <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-mint-500/12 px-3 py-1 text-xs font-medium text-mint-400">
            <span aria-hidden="true">🎁</span> {{ celebrate.reward }}
          </p>
          <p class="mt-4 text-xs text-subtle">Примените бонусы к следующей покупке или найдите новое событие.</p>
        </div>
      </template>
      <template #footer>
        <div class="flex gap-2">
          <NButton variant="secondary" @click="goBonus">Бонусы</NButton>
          <NButton variant="accent" block @click="goCatalog">Найти событие</NButton>
        </div>
      </template>
    </NModal>
  </div>
</template>
