<script setup lang="ts">
/**
 * Мои билеты.
 *
 * Активные билеты отделены от прошедших: искать нужный среди архива за минуту
 * до входа — худшее, что можно предложить пользователю. Активный билет сразу
 * крупный и с QR; прошедшие — свёрнуты до списка.
 *
 * Данные больше не берутся из lib/mock.ts — страница запрашивает реальные
 * билеты покупателя через GET /api/v1/my-tickets (идентификация по
 * X-Cart-Token, см. TicketController::mine). Загрузка/ошибка/пусто — явные
 * состояния вместо молчаливого показа чужих демо-данных.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import TicketCard from '@/components/storefront/TicketCard.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import NButton from '@/components/ui/NButton.vue'
import { fetchMyTickets } from '@/lib/tickets'
import { ApiError } from '@/lib/api'
import type { TicketCard as TicketCardType } from '@/lib/types'

const router = useRouter()

const tickets = ref<TicketCardType[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    tickets.value = await fetchMyTickets()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить билеты'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const active = computed(() => tickets.value.filter((t) => t.status === 'issued'))
const past = computed(() => tickets.value.filter((t) => t.status !== 'issued'))
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight text-content">Мои билеты</h1>

    <p v-if="loading" class="mt-6 text-sm text-subtle">Загружаем ваши билеты…</p>

    <div v-else-if="error" class="surface-card mt-6 p-4">
      <p class="text-sm text-rose-400">{{ error }}</p>
      <NButton variant="secondary" class="mt-3" @click="load">Повторить</NButton>
    </div>

    <NEmptyState
      v-else-if="active.length === 0 && past.length === 0"
      class="surface-card mt-6"
      icon="◫"
      title="Билетов пока нет"
      description="Как только оплатите заказ, билет появится здесь — с QR, который работает без интернета."
      action-label="К афише"
      @action="router.push('/')"
    />

    <template v-else>
      <template v-if="active.length">
        <h2 class="mt-6 text-sm font-semibold uppercase tracking-wide text-subtle">Активные</h2>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
          <TicketCard v-for="ticket in active" :key="ticket.id" :ticket="ticket" />
        </div>

        <div class="surface-card mt-4 flex flex-wrap items-center justify-between gap-3 p-4">
          <div>
            <p class="text-sm font-medium text-content">Билеты тоже отправлены на почту</p>
            <p class="mt-0.5 text-xs text-subtle">Если письма нет, проверьте «Спам» — иногда он агрессивен к PDF</p>
          </div>
          <NButton variant="secondary" class="flex-none" @click="load">Обновить</NButton>
        </div>
      </template>

      <template v-if="past.length">
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-subtle">Прошедшие</h2>
        <div class="mt-3 grid gap-3 lg:grid-cols-2">
          <TicketCard v-for="ticket in past" :key="ticket.id" :ticket="ticket" />
        </div>
      </template>
    </template>
  </div>
</template>
