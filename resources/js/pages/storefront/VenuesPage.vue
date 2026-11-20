<script setup lang="ts">
/** Площадки: каталог залов с адресами и ссылками на страницы площадок. */
import { onMounted, ref } from 'vue'
import { get } from '@/lib/api'

interface Venue {
  public_id: string
  name: string
  slug: string
  city?: string | null
  address?: string | null
}

const venues = ref<Venue[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await get<Venue[]>('/venues?per_page=100')
    venues.value = res.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить площадки'
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-8 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight text-content">Залы и площадки</h1>
    <p class="mt-1 text-sm text-muted">Площадки, на которых проходят наши мероприятия.</p>

    <input v-model="search" type="search" placeholder="Найти площадку"
           aria-label="Поиск площадки"
           class="mt-5 h-11 w-full max-w-md rounded-lg border border-line bg-surface-2 px-3 text-sm text-content placeholder:text-subtle focus:border-brand-400 focus:outline-none" />

    <p v-if="error" class="mt-5 text-sm text-rose-400">{{ error }}</p>
    <p v-else-if="loading" class="mt-5 text-sm text-muted">Загружаем площадки…</p>
    <div v-else class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <a v-for="venue in venues.filter(v => (v.name + ' ' + (v.city ?? '')).toLowerCase().includes(search.toLowerCase()))"
         :key="venue.public_id"
         :href="`/venue/${venue.slug}`"
         class="rounded-xl border border-line bg-surface-2 p-4 transition hover:border-brand-500">
        <span class="block text-sm font-semibold text-content">{{ venue.name }}</span>
        <span class="mt-1 block text-xs text-muted">{{ [venue.city, venue.address].filter(Boolean).join(', ') }}</span>
      </a>
    </div>
  </div>
</template>
