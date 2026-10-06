<script setup lang="ts">
/**
 * Обратный отсчёт до события.
 *
 * Создаёт ощущение «продажи идут»: до премьеры остаётся N дней — это сильнее,
 * чем просто дата в карточке. Цель либо ближайший сеанс из афиши (автоматически),
 * либо дата, заданная в конструкторе.
 *
 * Таймер обновляется раз в секунду, но цифры дней/часов пересчитываются в
 * computed — лишних тиков и «дёрганья» разметки нет.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCatalogStore } from '@/stores/catalog'
import { dateFull, time } from '@/lib/format'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const catalog = useCatalogStore()
const router = useRouter()

const eventId = computed(() => setting<string>(props.section.settings, 'eventId', ''))
const target = computed(() => setting<string>(props.section.settings, 'target', ''))
const label = computed(() => setting<string>(props.section.settings, 'label', 'До начала'))

const now = ref(Date.now())
let timer: number | undefined

onMounted(() => {
  catalog.load()
  timer = window.setInterval(() => {
    now.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
})

const chosen = computed(() => {
  if (eventId.value) {
    const found = catalog.events.find((e) => e.id === eventId.value || e.slug === eventId.value)
    if (found) {
      const startsAt = found.sessions?.[0]?.startsAt ?? found.sessions?.[0]?.starts_at ?? ''
      return { event: found, startsAt }
    }
  }
  const next = catalog.nextSession
  if (next) return { event: next.event, startsAt: next.startsAt }
  return null
})

const targetTime = computed(() => {
  if (target.value) {
    const parsed = new Date(target.value).getTime()
    if (!Number.isNaN(parsed)) return parsed
  }
  const at = chosen.value?.startsAt
  const parsed = at ? new Date(at).getTime() : NaN
  return Number.isNaN(parsed) ? null : parsed
})

const remaining = computed(() => {
  const to = targetTime.value
  if (!to) return null
  const diff = Math.max(0, to - now.value)
  return {
    days: Math.floor(diff / 86400000),
    hours: Math.floor((diff % 86400000) / 3600000),
    minutes: Math.floor((diff % 3600000) / 60000),
    seconds: Math.floor((diff % 60000) / 1000),
    done: diff === 0,
  }
})

const UNITS = computed(() => {
  const r = remaining.value
  if (!r) return []
  return [
    { value: r.days, unit: 'дней', one: 'день', few: 'дня' },
    { value: r.hours, unit: 'часов', one: 'час', few: 'часа' },
    { value: r.minutes, unit: 'минут', one: 'минута', few: 'минуты' },
    { value: r.seconds, unit: 'секунд', one: 'секунда', few: 'секунды' },
  ]
})

function unitLabel(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (mod10 === 1 && mod100 !== 11) return one
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few
  return many
}

function open(): void {
  const event = chosen.value?.event
  if (event) router.push(`/event/${event.slug || event.id}`)
}
</script>

<template>
  <div
    class="relative overflow-hidden rounded-2xl border border-line bg-surface p-5 sm:p-7"
    :style="{ backgroundImage: 'var(--gradient-stage)' }"
  >
    <div class="flex flex-wrap items-center justify-between gap-6">
      <div class="min-w-0">
        <p class="text-2xs uppercase tracking-wider text-subtle">{{ label }}</p>
        <p v-if="chosen" class="mt-1 truncate text-lg font-semibold text-content sm:text-xl">
          {{ chosen.event.title }}
        </p>
        <p v-if="chosen?.startsAt" class="mt-0.5 text-sm text-muted">
          {{ dateFull(chosen.startsAt) }} · {{ time(chosen.startsAt) }}
        </p>
      </div>

      <div v-if="remaining" class="flex gap-2 sm:gap-3">
        <div
          v-for="unit in UNITS"
          :key="unit.unit"
          class="grid min-w-[62px] place-items-center rounded-xl border border-line bg-surface-2 px-3 py-2.5"
        >
          <span class="text-2xl font-bold tabular-nums text-content sm:text-3xl">
            {{ String(unit.value).padStart(2, '0') }}
          </span>
          <span class="text-2xs uppercase tracking-wide text-subtle">
            {{ unitLabel(unit.value, unit.one, unit.few, unit.unit) }}
          </span>
        </div>
      </div>

      <p v-else class="text-sm text-subtle">Ближайших событий пока нет.</p>
    </div>

    <button
      v-if="chosen"
      type="button"
      class="mt-5 inline-flex h-11 items-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-brand-on transition-colors hover:bg-brand-400"
      @click="open"
    >
      Выбрать места
    </button>
  </div>
</template>
