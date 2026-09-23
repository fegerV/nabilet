<script setup lang="ts">
/**
 * Легенда карты зала.
 *
 * Вынесена рядом с картой, а не спрятана в подсказку: цвет в схеме зала — это
 * условие покупки, и пользователь не должен угадывать, что значит серый.
 */
import { SEAT_LEGEND } from '@/lib/hall'
import type { SeatState } from '@/lib/types'
import { cn } from '@/lib/cn'

withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })

const SWATCH: Record<SeatState, string> = {
  free: 'seat',
  selected: 'seat seat--selected',
  held: 'seat seat--held',
  sold: 'seat seat--sold',
  unavailable: 'seat seat--unavailable',
  vip: 'seat seat--vip',
  accessible: 'seat seat--accessible',
}

// Порядок задаёт hall.ts — легенда и карта обязаны совпадать.
const items = SEAT_LEGEND
</script>

<template>
  <ul
    :class="
      cn(
        'flex flex-wrap gap-x-3 gap-y-1.5',
        compact ? 'text-2xs' : 'text-xs',
      )
    "
  >
    <li v-for="item in items" :key="item.state" class="flex items-center gap-1.5 text-muted">
      <span :class="SWATCH[item.state]" class="h-3.5 w-3.5 flex-none !rounded-[3px] hover:transform-none" aria-hidden="true" />
      {{ item.label }}
    </li>
  </ul>
</template>
