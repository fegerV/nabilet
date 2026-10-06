<script setup lang="ts">
/**
 * Герой-баннер.
 *
 * Заголовок, подзаголовок и кнопка настраиваются в конструкторе. Фон —
 * либо картинка организатора, либо градиент из бренд-кистей: афиша без
 * загруженной картинки не должна выглядеть «недоделанной».
 *
 * Высота настраивается, потому что у театра и у ночного клуба разные
 * expectations: первому нужен воздух, второму — плотный первый экран.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()
const router = useRouter()

const badge = computed(() => setting<string>(props.section.settings, 'badge', ''))
const title = computed(() => setting<string>(props.section.settings, 'title', ''))
const subtitle = computed(() => setting<string>(props.section.settings, 'subtitle', ''))
const ctaLabel = computed(() => setting<string>(props.section.settings, 'ctaLabel', ''))
const ctaLink = computed(() => setting<string>(props.section.settings, 'ctaLink', '/'))
const image = computed(() => setting<string>(props.section.settings, 'image', ''))
const align = computed(() => setting<'left' | 'center'>(props.section.settings, 'align', 'left'))
const height = computed(() => setting<'compact' | 'medium' | 'tall'>(props.section.settings, 'height', 'medium'))
const showSearch = computed(() => setting<boolean>(props.section.settings, 'showSearch', false))

const HEIGHTS: Record<string, string> = {
  compact: 'py-10 sm:py-14',
  medium: 'py-14 sm:py-20',
  tall: 'py-20 sm:py-28',
}

const search = ref('')

function goCta(): void {
  if (!ctaLink.value) return
  // Внутренние пути ведём роутером, внешние — браузером: иначе https-ссылка
  // организатора превратилась бы в /https://…
  if (ctaLink.value.startsWith('/')) router.push(ctaLink.value)
  else window.open(ctaLink.value, '_blank', 'noopener')
}

function submitSearch(): void {
  const q = search.value.trim()
  if (q) router.push({ path: '/', query: { q } })
}
</script>

<template>
  <div :class="['relative overflow-hidden', HEIGHTS[height] ?? HEIGHTS.medium]">
    <!-- Фон: картинка организатора или подсветка сцены из бренд-градиента -->
    <div
      class="absolute inset-0 bg-cover bg-center"
      :style="image ? { backgroundImage: `url(${image})` } : undefined"
      aria-hidden="true"
    />
    <div v-if="image" class="absolute inset-0 bg-overlay/55" aria-hidden="true" />
    <div v-else class="pointer-events-none absolute inset-0 bg-stage" aria-hidden="true" />

    <div
      :class="[
        'relative mx-auto max-w-content px-4 sm:px-6',
        align === 'center' ? 'text-center' : 'text-left',
      ]"
    >
      <div :class="align === 'center' ? 'mx-auto max-w-3xl' : 'max-w-3xl'">
        <p
          v-if="badge"
          class="mb-4 inline-flex items-center gap-2 rounded-full border border-brand-500/30 bg-brand-500/10 px-3 py-1 text-xs font-medium text-brand-300 backdrop-blur"
        >
          <span class="h-1.5 w-1.5 animate-pulse-ring rounded-full bg-brand-400" aria-hidden="true" />
          {{ badge }}
        </p>

        <h1 class="text-balance text-3xl font-bold leading-tight tracking-tight text-content sm:text-5xl">
          {{ title }}
        </h1>

        <p v-if="subtitle" class="mt-4 text-pretty text-base text-muted sm:text-lg">{{ subtitle }}</p>

        <div v-if="ctaLabel" :class="['mt-7 flex flex-wrap gap-3', align === 'center' ? 'justify-center' : '']">
          <button
            type="button"
            class="inline-flex h-12 items-center gap-2 rounded-lg bg-brand-500 px-6 text-base font-medium text-brand-on shadow-brand transition-all duration-120 hover:bg-brand-400 active:bg-brand-600"
            @click="goCta"
          >
            {{ ctaLabel }}
          </button>
        </div>

        <!-- Поиск прямо в герое: длинная афиша без поиска — мучение -->
        <form v-if="showSearch" :class="['mt-8', align === 'center' ? 'mx-auto max-w-xl' : 'max-w-xl']" role="search" @submit.prevent="submitSearch">
          <div class="relative">
            <span aria-hidden="true" class="absolute left-4 top-1/2 -translate-y-1/2 text-base text-subtle">⌕</span>
            <input
              v-model="search"
              type="search"
              placeholder="Событие, площадка или город"
              aria-label="Поиск событий"
              class="h-14 w-full rounded-xl border border-line bg-surface/90 pl-11 pr-24 text-base text-content shadow-sm backdrop-blur placeholder:text-subtle focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25"
            />
            <button
              type="submit"
              class="absolute right-2 top-1/2 h-9 -translate-y-1/2 rounded-lg bg-brand-500 px-4 text-sm font-medium text-brand-on transition-colors hover:bg-brand-400"
            >
              Найти
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
