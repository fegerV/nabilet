<script setup lang="ts">
/**
 * Итог заказа рядом с картой зала.
 *
 * Главное здесь — порядок сумм. Пользователь проверяет: «два билета по 12 000,
 * итого 24 000». Если сервисный сбор всплывает только на оплате, доверие
 * рушится, поэтому все составляющие видны сразу, до платежа.
 */
import { computed } from 'vue'
import NButton from '../ui/NButton.vue'
import NCountdown from '../ui/NCountdown.vue'
import { money, seatsLabel, ticketsLabel } from '@/lib/format'
import type { CartSeat } from '@/stores/cart'
import { cn } from '@/lib/cn'

const props = withDefaults(
  defineProps<{
    seats: CartSeat[]
    subtotalMinor: number
    discountMinor?: number
    holdSecondsLeft?: number
    /** Сервисный сбор в minor units: показываем отдельной строкой, не прячем. */
    feeMinor?: number
    ctaLabel?: string
    disabled?: boolean
    dense?: boolean
  }>(),
  { discountMinor: 0, feeMinor: 0, holdSecondsLeft: 0, ctaLabel: 'Оформить заказ', dense: false },
)

const emit = defineEmits<{ remove: [id: string]; extend: []; submit: []; clear: [] }>()

const serviceFee = computed(() => props.feeMinor)
const totalMinor = computed(() => Math.max(0, props.subtotalMinor + serviceFee.value - props.discountMinor))
const sorted = computed(() => [...props.seats].sort((a, b) => a.row - b.row || a.number - b.number))
const empty = computed(() => props.seats.length === 0)

const KIND_LABEL: Record<CartSeat['kind'], string | null> = {
  standard: null,
  vip: 'VIP',
  accessible: 'Маломобильное',
}
</script>

<template>
  <div :class="cn('flex flex-col rounded-xl border border-line bg-surface', dense ? '' : 'overflow-hidden')">
    <div v-if="!dense" class="flex items-center justify-between border-b border-line px-4 py-3">
      <h2 class="text-sm font-semibold text-content">Ваш выбор</h2>
      <button
        v-if="!empty"
        type="button"
        class="text-xs text-subtle transition-colors hover:text-rose-400"
        @click="emit('clear')"
      >
        Очистить
      </button>
    </div>

    <!-- Таймер удержания -->
    <div v-if="holdSecondsLeft > 0" :class="dense ? 'p-3' : 'border-b border-line p-3'">
      <NCountdown :seconds-left="holdSecondsLeft" @extend="emit('extend')" />
    </div>

    <!-- Список мест -->
    <div :class="cn('min-h-0 flex-1 overflow-y-auto', empty ? 'p-4' : 'p-3')">
      <p v-if="empty" class="py-6 text-center text-sm text-subtle">
        Выберите места на схеме зала — они появятся здесь
      </p>

      <ul v-else class="space-y-1.5">
        <li
          v-for="seat in sorted"
          :key="seat.id"
          class="group flex items-center gap-2.5 rounded-lg border border-line bg-surface-2 px-2.5 py-2"
        >
          <span
            :class="
              cn(
                'grid h-7 w-7 flex-none place-items-center rounded-md text-2xs font-semibold',
                seat.kind === 'vip' ? 'bg-gold-gradient text-ink-950' : 'bg-surface-3 text-muted',
              )
            "
          >{{ seat.row }}</span>

          <div class="min-w-0 flex-1">
            <p class="truncate text-sm text-content">
              {{ seat.sector }}, место {{ seat.number }}
              <span v-if="KIND_LABEL[seat.kind]" class="ml-1 text-2xs text-brand-400">{{ KIND_LABEL[seat.kind] }}</span>
            </p>
          </div>

          <span class="flex-none text-sm tabular-nums text-content">{{ money(seat.priceMinor) }}</span>

          <button
            type="button"
            class="flex-none text-subtle transition-colors hover:text-rose-400"
            :aria-label="`Убрать место ${seat.number} ряда ${seat.row}`"
            @click="emit('remove', seat.id)"
          >✕</button>
        </li>
      </ul>
    </div>

    <!-- Суммы -->
    <div :class="cn('flex-none', dense ? 'px-3 pb-3' : 'border-t border-line p-4')">
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between">
          <dt class="text-muted">{{ ticketsLabel(seats.length) }} · {{ seatsLabel(seats.length) }}</dt>
          <dd class="tabular-nums text-content">{{ money(subtotalMinor) }}</dd>
        </div>
        <div v-if="serviceFee > 0" class="flex justify-between">
          <dt class="text-muted">Сервисный сбор</dt>
          <dd class="tabular-nums text-content">{{ money(serviceFee) }}</dd>
        </div>
        <div v-if="discountMinor > 0" class="flex justify-between">
          <dt class="text-mint-400">Скидка по промокоду</dt>
          <dd class="tabular-nums text-mint-400">−{{ money(discountMinor) }}</dd>
        </div>
        <div class="flex items-baseline justify-between border-t border-line pt-2">
          <dt class="font-medium text-content">Итого</dt>
          <dd class="text-xl font-semibold tabular-nums text-content">{{ money(totalMinor) }}</dd>
        </div>
      </dl>

      <NButton
        variant="accent"
        size="lg"
        block
        class="mt-3"
        :disabled="empty || disabled"
        @click="emit('submit')"
      >
        {{ ctaLabel }}
      </NButton>
    </div>
  </div>
</template>
