<script setup lang="ts">
/**
 * Цифры площадки.
 *
 * «Авто» считает по реальной афише: сколько мероприятий, сколько городов,
 * сколько ближайших сеансов. Вручную — когда организатор хочет показать
 * «10 лет на сцене»: такие цифры из базы не берутся.
 *
 * Пустые автоматические значения не показываем: «0 мероприятий» продаёт
 * хуже, чем отсутствие блока.
 */
import { computed, onMounted } from 'vue'
import { useCatalogStore } from '@/stores/catalog'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const catalog = useCatalogStore()

const source = computed(() => setting<'auto' | 'manual'>(props.section.settings, 'source', 'auto'))
const manual = computed(
  () => setting<Array<{ label: string; value: string }>>(props.section.settings, 'items', []),
)

onMounted(() => catalog.load())

const items = computed(() => {
  if (source.value === 'manual') return manual.value

  const cities = new Set<string>()
  for (const event of catalog.events) {
    const city = typeof event.venue === 'object' ? event.venue?.city : ''
    if (city) cities.add(city)
  }

  const auto = [
    { value: String(catalog.events.length), label: 'мероприятий в афише' },
    // Ноль городов отфильтруется ниже: «0 городов» хуже отсутствия блока.
    { value: String(cities.size), label: 'городов' },
    { value: String(catalog.upcoming.length), label: 'ближайших сеансов' },
  ]

  return auto.filter((item) => item.value !== '0' || source.value === 'manual')
})
</script>

<template>
  <!--
    На телефоне блок остаётся СТРОКОЙ из трёх колонок, а не столбиком:
    столбик из трёх карточек по ~100px отодвигал афишу за пределы первого
    экрана — витрина открывалась цифрами вместо мероприятий.
  -->
  <dl v-if="items.length" class="grid grid-cols-3 gap-2 sm:gap-3">
    <div
      v-for="item in items"
      :key="item.label"
      class="rounded-xl border border-line bg-surface px-2 py-3 text-center sm:px-4 sm:py-5"
    >
      <dt class="text-[10px] uppercase leading-tight tracking-wide text-subtle sm:text-2xs">{{ item.label }}</dt>
      <dd class="mt-0.5 bg-brand-gradient bg-clip-text text-2xl font-bold tabular-nums text-transparent sm:mt-1 sm:text-4xl">
        {{ item.value }}
      </dd>
    </div>
  </dl>

  <p v-else class="text-sm text-subtle">Показатели появятся после публикации мероприятий.</p>
</template>
