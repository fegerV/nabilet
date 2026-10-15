<script setup lang="ts">
/**
 * Мои билеты.
 *
 * Активные билеты отделены от прошедших: искать нужный среди архива за минуту
 * до входа — худшее, что можно предложить пользователю. Активный билет сразу
 * крупный и с QR; прошедшие — свёрнуты до списка.
 *
 * Страница запрашивает реальные билеты покупателя через GET /api/v1/my-tickets
 * (идентификация по X-Cart-Token, см. TicketController::mine). Демо-данные
 * (`lib/mock.ts`) удалены из проекта: загрузка/ошибка/пусто — явные состояния
 * вместо молчаливого показа чужих билетов.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import TicketCard from '@/components/storefront/TicketCard.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import NButton from '@/components/ui/NButton.vue'
import NModal from '@/components/ui/NModal.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import { fetchMyTickets, resendTicket } from '@/lib/tickets'
import { ApiError } from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import type { TicketCard as TicketCardType } from '@/lib/types'

const router = useRouter()
const ui = useUiStore()

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

/**
 * Заказы, по которым можно переслать письмо с билетом.
 *
 * Дедупликация по заказу: в одном письме уходит весь заказ, и предлагать
 * покупателю выбрать «билет» вместо «заказа» значило бы обещать отправку
 * одного места, чего сервер не умеет. Активные заказы идут первыми.
 */
const orders = computed(() => {
  const seen = new Map<string, { value: string; label: string }>()
  for (const t of [...active.value, ...past.value]) {
    const id = t.orderId
    if (id === '' || id === null || id === undefined) continue
    const key = String(id)
    if (seen.has(key)) continue
    seen.set(key, { value: key, label: `${t.eventTitle} — ${t.venue}` })
  }
  return [...seen.values()]
})

const resendOpen = ref(false)
const resendOrderId = ref('')
const resendEmail = ref('')
const resending = ref(false)
const resendError = ref<string | null>(null)

function openResend(): void {
  resendError.value = null
  resendEmail.value = ''
  resendOrderId.value = orders.value[0]?.value ?? ''
  resendOpen.value = true
}

/**
 * Переслать письмо с билетом, при необходимости — на исправленный адрес.
 *
 * Ошибки разбираем по коду, а не показываем общий текст: 429 (лимит) и 404
 * (чужой браузер) требуют от покупателя РАЗНЫХ действий, и «что-то пошло не
 * так» здесь бесполезно — он и так не получил билет.
 */
async function submitResend(): Promise<void> {
  if (!resendOrderId.value) {
    resendError.value = 'Заказ не найден. Обновите список билетов и попробуйте снова.'
    return
  }

  resending.value = true
  resendError.value = null

  try {
    const sentTo = await resendTicket(resendOrderId.value, resendEmail.value)
    resendOpen.value = false
    ui.notify('mint', 'Письмо отправлено', `Билеты отправлены на ${sentTo}`)
  } catch (e) {
    if (e instanceof ApiError && e.status === 429) {
      resendError.value = 'Слишком много попыток. Подождите минуту и повторите.'
    } else if (e instanceof ApiError && e.status === 404) {
      resendError.value =
        'Заказ не найден для этого браузера. Откройте «Мои билеты» там, где оформляли покупку.'
    } else if (e instanceof ApiError && e.status === 409) {
      resendError.value = 'Билет ещё не выпущен: письмо отправляется после оплаты заказа.'
    } else {
      resendError.value = e instanceof ApiError ? e.message : 'Не удалось отправить письмо'
    }
  } finally {
    resending.value = false
  }
}
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
            <p class="mt-0.5 text-xs text-subtle">
              Нет письма? Проверьте «Спам» — или отправьте билет на другой адрес
            </p>
          </div>
          <div class="flex flex-none gap-2">
            <NButton variant="secondary" @click="load">Обновить</NButton>
            <NButton variant="accent" @click="openResend">Не пришло письмо?</NButton>
          </div>
        </div>
      </template>

      <template v-if="past.length">
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-subtle">Прошедшие</h2>
        <div class="mt-3 grid gap-3 lg:grid-cols-2">
          <TicketCard v-for="ticket in past" :key="ticket.id" :ticket="ticket" />
        </div>
      </template>
    </template>

    <!--
      Повторная отправка билета. Существует потому, что письмо уходит на адрес,
      введённый при оформлении: опечатка в одну букву означала, что билет не
      придёт никогда. Здесь адрес можно исправить и запросить письмо заново.
    -->
    <NModal v-model:open="resendOpen" title="Отправить билет ещё раз">
      <p class="text-sm text-muted">
        Письмо с билетами уходит на адрес, который вы указали при оформлении. Если в нём
        опечатка — впишите правильный адрес: билеты уйдут на него, а заказ останется тем же.
      </p>

      <div class="mt-4 space-y-3">
        <NSelect
          v-if="orders.length > 1"
          v-model="resendOrderId"
          label="Заказ"
          :options="orders"
        />

        <NInput
          v-model="resendEmail"
          label="E-mail для билетов"
          placeholder="ivan@example.com"
          type="email"
          inputmode="email"
          autocomplete="email"
          hint="Оставьте пустым, чтобы отправить на адрес из заказа"
        />
      </div>

      <p v-if="resendError" class="mt-3 text-xs text-rose-400">{{ resendError }}</p>

      <template #footer>
        <NButton variant="ghost" @click="resendOpen = false">Отмена</NButton>
        <NButton variant="accent" :loading="resending" @click="submitResend">
          Отправить билеты
        </NButton>
      </template>
    </NModal>
  </div>
</template>
