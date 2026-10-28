<script setup lang="ts">
/**
 * «Мои розыгрыши» — билеты покупателя в «счастливом билете» (фаза 5).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ЭКРАН
 *   Участие в розыгрыше — следствие покупки, о котором покупатель не просил:
 *   заказ оплачен — билет заведён сам. Без экрана он узнал бы о розыгрыше
 *   только из письма победителю, а непобедитель — никогда. Здесь видно и
 *   «вы участвуете», и «ваш билет не выиграл, держите следующие призы» —
 *   честнее, чем молчание.
 *
 * ЧТО ЭКРАН НЕ ДЕЛАЕТ
 *   Не показывает заказы-билеты поштучно: «сколько всего билетов» уже есть
 *   в ответе сервера, а список заказов — дело экрана «Мои билеты». Здесь
 *   один розыгрыш — одна карточка.
 *
 * ПОБЕДА
 *   Приз из выигранного места показывается той же карточкой, с подтверждением
 *   отправки письма (`notified`). Единственное действие экрана — открыть
 *   публичную страницу итогова: результаты те же, что у всех.
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { ApiError } from '@/lib/api'
import { dateTime, plural } from '@/lib/format'
import { fetchMyRaffles, type RaffleParticipation } from '@/lib/raffles'

const router = useRouter()

const raffles = ref<RaffleParticipation[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    raffles.value = await fetchMyRaffles()
  } catch (e) {
    raffles.value = []
    error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить розыгрыши.'
  } finally {
    loading.value = false
  }
}

function openResults(publicId: string): void {
  void router.push(`/raffles/${publicId}`)
}

/** Есть ли выигрыш в этом розыгрыше. */
function myWin(raffle: RaffleParticipation): RaffleParticipation['won'][number] | undefined {
  return raffle.won[0]
}
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Мои розыгрыши</h1>
    <p class="mt-1.5 text-sm text-muted">
      «Счастливый билет»: каждый оплаченный заказ из окна розыгрыша становился билетом.
    </p>

    <p v-if="loading" class="mt-8 text-sm text-subtle">Загружаем участия…</p>

    <div
      v-else-if="error"
      class="mt-8 flex flex-wrap items-center gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400"
    >
      <span class="min-w-0">{{ error }}</span>
      <NButton variant="ghost" size="sm" @click="load">Повторить</NButton>
    </div>

    <NEmptyState
      v-else-if="raffles.length === 0"
      class="mt-8"
      icon="🎟"
      title="Участий пока нет"
      description="Когда организатор запустит розыгрыш, а ваши оплаченные заказы попадут в его окно, билеты появятся здесь."
    />

    <ul v-else class="mt-6 space-y-3">
      <li v-for="raffle in raffles" :key="raffle.public_id" class="surface-card p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-sm font-semibold text-content">{{ raffle.title }}</h2>
              <NStatusBadge kind="raffle" :status="raffle.status" />
            </div>
            <p class="mt-1 text-xs text-subtle">
              {{ raffle.entries_count }} {{ plural(raffle.entries_count, 'билет', 'билета', 'билетов') }} в розыгрыше
              · окно {{ dateTime(raffle.starts_at ?? '') }} — {{ dateTime(raffle.ends_at ?? '') }}
              <template v-if="raffle.drawn_at"> · тираж {{ dateTime(raffle.drawn_at) }}</template>
            </p>
          </div>

          <NButton size="sm" variant="secondary" @click="openResults(raffle.public_id)">Итоги</NButton>
        </div>

        <!-- Призы -->
        <ul v-if="raffle.prizes.length > 0" class="mt-3 flex flex-wrap gap-2">
          <li
            v-for="prize in raffle.prizes"
            :key="prize.place"
            class="rounded-full bg-surface-2 px-2.5 py-1 text-2xs text-muted"
          >
            <span class="font-semibold text-subtle">{{ prize.place }}</span>
            {{ prize.title }}
          </li>
        </ul>

        <!-- Результат для меня -->
        <p v-if="myWin(raffle)" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg bg-mint-500/10 px-3 py-2 text-sm">
          <span aria-hidden="true">🏆</span>
          <span class="font-medium text-mint-400">
            Вы победитель: {{ myWin(raffle)!.prize_title }} ({{ myWin(raffle)!.place }} место)
          </span>
          <span class="text-xs text-subtle">
            {{ myWin(raffle)!.notified ? 'письмо отправлено' : 'письмо придёт на почту' }}
          </span>
        </p>
        <p v-else-if="raffle.status === 'drawn'" class="mt-3 rounded-lg bg-surface-2 px-3 py-2 text-xs text-subtle">
          Ваши билеты не выиграли в этом розыгрыше — следите за следующими.
        </p>
        <p v-else class="mt-3 rounded-lg bg-surface-2 px-3 py-2 text-xs text-subtle">
          Тираж ещё не проведён: билеты на месте, ждём окна.
        </p>
      </li>
    </ul>
  </div>
</template>
