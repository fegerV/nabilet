<script setup lang="ts">
/**
 * Категории: чипсы или плитки.
 *
 * Источник «авто» берёт категории из реальной афиши — организатор не должен
 * вручную синхронизировать список жанров с мероприятиями. «Вручную» нужен
 * для рубрик, которых нет в данных («Детям», «Скоро распродано»).
 *
 * Клик ведёт на афишу с ?cat= — состояние фильтра живёт в адресе, поэтому
 * его можно переслать и пережить «назад».
 */
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCatalogStore } from '@/stores/catalog'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const catalog = useCatalogStore()
const router = useRouter()

const source = computed(() => setting<'auto' | 'manual'>(props.section.settings, 'source', 'auto'))
const style = computed(() => setting<'chips' | 'tiles'>(props.section.settings, 'style', 'chips'))
const manual = computed(
  () => setting<Array<{ label: string; icon?: string; query?: string }>>(props.section.settings, 'items', []),
)

onMounted(() => catalog.load())

const items = computed(() => {
  if (source.value === 'manual') {
    return manual.value.map((item) => ({
      label: item.label,
      icon: item.icon ?? '',
      query: item.query ?? item.label,
    }))
  }
  return catalog.categories.map((name) => ({ label: name, icon: '', query: name }))
})

/* Плиткам нужен цвет: берём бренд-кисть с разной прозрачностью,
 * чтобы рубрики не выглядели как один ковёр из одного цвета. */
const TILE_TONES = [
  'from-brand-500/85 to-brand-700/85',
  'from-accent-500/85 to-accent-700/85',
  'from-brand-700/85 to-brand-900/85',
  'from-accent-400/85 to-brand-600/85',
  'from-brand-400/85 to-accent-600/85',
  'from-brand-600/85 to-brand-400/85',
]

function tone(index: number): string {
  return TILE_TONES[index % TILE_TONES.length] ?? TILE_TONES[0]!
}

function pick(query: string): void {
  router.push({ path: '/', query: { cat: query } })
}
</script>

<template>
  <!-- Авто-источник без данных не показываем вовсе: сообщение «категорий нет»
       на публичной витрине объясняет внутреннюю кухню покупателю, которому
       это безразлично. Ручной список без пунктов — другое дело: его надо
       заполнить, и пустой блок на это укажет. -->
  <div v-if="!items.length && source === 'manual'" class="text-sm text-subtle">
    Добавьте рубрики в конструкторе витрины.
  </div>
  <div v-else-if="!items.length" class="hidden" />

  <div v-else-if="style === 'chips'" class="flex flex-wrap gap-2">
    <button
      v-for="item in items"
      :key="item.label"
      type="button"
      class="group inline-flex items-center gap-2 rounded-full border border-line bg-surface px-4 py-2 text-sm text-muted transition-all duration-120 hover:-translate-y-0.5 hover:border-brand-500/50 hover:text-content"
      @click="pick(item.query)"
    >
      <span v-if="item.icon" aria-hidden="true">{{ item.icon }}</span>
      {{ item.label }}
      <span aria-hidden="true" class="text-subtle transition-transform group-hover:translate-x-0.5">→</span>
    </button>
  </div>

  <div v-else class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
    <button
      v-for="(item, index) in items"
      :key="item.label"
      type="button"
      :class="[
        'group relative flex h-24 items-end overflow-hidden rounded-xl bg-gradient-to-br p-4 text-left transition-transform duration-200 hover:-translate-y-0.5',
        tone(index),
      ]"
      @click="pick(item.query)"
    >
      <span class="relative text-base font-semibold text-white drop-shadow-sm">{{ item.label }}</span>
      <span
        aria-hidden="true"
        class="absolute right-3 top-3 text-lg text-white/70 transition-transform group-hover:translate-x-0.5"
        >→</span
      >
    </button>
  </div>
</template>
