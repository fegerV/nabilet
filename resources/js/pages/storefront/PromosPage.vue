<script setup lang="ts">
/** Акции и розыгрыши: публичные розыгрыши «счастливого билета». */
import { onMounted, ref } from 'vue'
import { get } from '@/lib/api'
import { RouterLink } from 'vue-router'

interface Raffle {
  public_id: string
  title: string
  status: string
  starts_at?: string | null
  ends_at?: string | null
  prizes?: Array<{ place: number; title: string }>
}

const raffles = ref<Raffle[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const res = await get<Raffle[]>('/raffles')
    raffles.value = res.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить акции'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-8 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight text-content">Акции</h1>
    <p class="mt-1 text-sm text-muted">Розыгрыши и специальные предложения для зрителей.</p>

    <p v-if="error" class="mt-5 text-sm text-rose-400">{{ error }}</p>
    <p v-else-if="loading" class="mt-5 text-sm text-muted">Загружаем акции…</p>
    <div v-else-if="raffles.length" class="mt-5 grid gap-3 sm:grid-cols-2">
      <RouterLink v-for="raffle in raffles" :key="raffle.public_id"
                  :to="`/raffles/${raffle.public_id}`"
                  class="rounded-xl border border-line bg-surface-2 p-4 transition hover:border-brand-500">
        <span class="block text-sm font-semibold text-content">{{ raffle.title }}</span>
        <span class="mt-1 block text-xs text-subtle">Статус: {{ raffle.status }}</span>
        <ul v-if="raffle.prizes?.length" class="mt-2 space-y-0.5">
          <li v-for="prize in raffle.prizes" :key="prize.place" class="text-xs text-muted">
            {{ prize.place }} место — {{ prize.title }}
          </li>
        </ul>
      </RouterLink>
    </div>
    <p v-else class="mt-5 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-muted">
      Сейчас активных акций нет. Загляните позже.
    </p>
  </div>
</template>
