<script setup lang="ts">
/** Календарь: все события, сгруппированные по дате начала. */
import { computed, onMounted } from 'vue'
import { useCatalogStore } from '@/stores/catalog'
import { useRouter } from 'vue-router'
import { formatDate } from '@/lib/format'

const catalog = useCatalogStore()
const router = useRouter()

const groups = computed(() => {
  const map = new Map<string, typeof catalog.events>()
  for (const event of catalog.events) {
    const key = formatDate(new Date(catalog.startsAt(event)).toISOString())
    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(event)
  }
  return [...map.entries()]
})

onMounted(() => { if (catalog.events.length === 0) void catalog.load() })
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-8 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight text-content">Календарь</h1>
    <p class="mt-1 text-sm text-muted">Все предстоящие события по датам.</p>

    <p v-if="catalog.loading" class="mt-6 text-sm text-muted">Загружаем афишу…</p>
    <div v-else class="mt-6 space-y-8">
      <section v-for="[date, events] in groups" :key="date">
        <h2 class="border-b border-line pb-2 text-sm font-semibold uppercase tracking-wide text-content">{{ date }}</h2>
        <ul class="mt-3 grid gap-2 sm:grid-cols-2">
          <li v-for="event in events" :key="event.public_id">
            <button type="button"
                    class="w-full rounded-xl border border-line bg-surface-2 p-3 text-left transition hover:border-brand-500"
                    @click="router.push(`/event/${event.slug}/${event.public_id}`)">
              <span class="block truncate text-sm font-medium text-content">{{ event.title }}</span>
              <span class="mt-1 block truncate text-xs text-subtle">{{ catalog.venueName(event) }}</span>
            </button>
          </li>
        </ul>
      </section>
      <p v-if="groups.length === 0" class="text-sm text-muted">В афише пока нет событий с объявленной датой.</p>
    </div>
  </div>
</template>
