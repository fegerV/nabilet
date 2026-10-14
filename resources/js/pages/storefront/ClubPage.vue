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
import { customerUser } from '@/lib/customer'
import { plural } from '@/lib/format'
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
const me = customerUser()

const displayName = computed(() => {
  const name = [me?.first_name, me?.last_name].filter(Boolean).join(' ').trim()
  return name === '' ? me?.email ?? 'Зритель' : name
})

/* ── Демо-прогресс клуба (бриф §4: «Уровень 2 · 450 бонусов») ───────────── */
const demoVisits = 7
function levelFor(v: number): number {
  if (v >= 25) return 4
  if (v >= 10) return 3
  if (v >= 5) return 2
  return 1
}
const LEVEL_NEXT: Record<number, number> = { 1: 5, 2: 10, 3: 25, 4: Infinity }
const level = computed(() => levelFor(demoVisits))
const levelNext = computed(() => LEVEL_NEXT[level.value])
const levelPct = computed(() =>
  levelNext.value === Infinity ? 100 : Math.min(100, Math.round((demoVisits / levelNext.value) * 100)),
)
const levelRemaining = computed(() =>
  levelNext.value === Infinity ? 0 : Math.max(0, levelNext.value - demoVisits),
)

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
})

function goCatalog(): void {
  router.push('/')
}
function goBonus(): void {
  router.push('/bonus')
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
            Уровень {{ level }}
          </span>
          <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-sm font-semibold backdrop-blur">
            <span class="text-base leading-none">✦</span>
            {{ loyalty.bonusesCount }} {{ plural(loyalty.bonusesCount, 'бонус', 'бонуса', 'бонусов') }}
          </span>
          <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-sm font-semibold backdrop-blur">
            {{ demoVisits }} {{ plural(demoVisits, 'посещение', 'посещения', 'посещений') }}
          </span>
        </div>

        <div class="mt-5 max-w-md">
          <div class="h-2 w-full overflow-hidden rounded-full bg-white/20">
            <div class="h-full rounded-full bg-white/90 transition-all duration-320 ease-out" :style="{ width: levelPct + '%' }" />
          </div>
          <p class="mt-1.5 text-2xs text-white/75">
            <template v-if="levelRemaining > 0">
              Ещё {{ levelRemaining }} {{ plural(levelRemaining, 'посещение', 'посещения', 'посещений') }} — и вы на Уровень {{ level + 1 }}
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
          <p class="text-lg font-bold tabular-nums text-content">{{ level }}</p>
          <p class="text-2xs text-subtle">уровень зрителя</p>
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
