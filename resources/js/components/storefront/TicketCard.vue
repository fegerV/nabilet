<script setup lang="ts">
/**
 * Билет.
 *
 * QR — главное, а не подпись под ним: на входе билет сканируют, а не читают.
 * Поэтому код крупный, с тихой зоной, и не инвертируется в тёмной теме —
 * сканеры надёжнее читают тёмное на светлом. Ряд, место и время набраны крупно:
 * это те три вещи, которые человек ищет в последние минуты перед входом.
 */
import { onMounted, ref } from 'vue'
import QRCode from 'qrcode'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { money, dateFull, time } from '@/lib/format'
import type { TicketCard as TicketCardType } from '@/lib/types'
import { cn } from '@/lib/cn'

const props = defineProps<{ ticket: TicketCardType }>()

const qrUrl = ref('')

onMounted(async () => {
  try {
    qrUrl.value = await QRCode.toDataURL(props.ticket.qrPayload, {
      width: 320,
      margin: 1,
      errorCorrectionLevel: 'M',
      color: { dark: '#120F24', light: '#FFFFFF' },
    })
  } catch {
    qrUrl.value = ''
  }
})

const used = props.ticket.status === 'used'
const inactive = props.ticket.status !== 'issued'
</script>

<template>
  <article
    :class="
      cn(
        'relative overflow-hidden rounded-xl border bg-surface transition-opacity',
        inactive ? 'border-line opacity-70' : 'border-brand-500/35 shadow-md',
      )
    "
  >
    <!-- Перфорация: билет должен выглядеть билетом, а не карточкой.
         На мобильном QR стекает под информацией, иначе не помещается. -->
    <div class="flex flex-col sm:flex-row">
      <div class="min-w-0 flex-1 p-4">
        <div class="flex min-w-0 items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <p class="text-2xs uppercase tracking-wider text-subtle">{{ ticket.sector }}</p>
            <h3 class="mt-0.5 truncate text-base font-semibold text-content">{{ ticket.eventTitle }}</h3>
            <p class="mt-0.5 truncate text-xs text-muted">{{ ticket.venue }}</p>
          </div>
          <NStatusBadge kind="ticket" :status="ticket.status" />
        </div>

        <dl class="mt-4 grid grid-cols-3 gap-3">
          <div>
            <dt class="text-2xs uppercase tracking-wide text-subtle">Ряд</dt>
            <dd class="text-xl font-bold tabular-nums text-content">{{ ticket.row }}</dd>
          </div>
          <div>
            <dt class="text-2xs uppercase tracking-wide text-subtle">Место</dt>
            <dd class="text-xl font-bold tabular-nums text-content">{{ ticket.seat }}</dd>
          </div>
          <div>
            <dt class="text-2xs uppercase tracking-wide text-subtle">Цена</dt>
            <dd class="text-base font-semibold tabular-nums text-content">{{ money(ticket.priceMinor) }}</dd>
          </div>
        </dl>

        <p class="mt-3 flex items-center gap-1.5 text-sm text-content">
          <span aria-hidden="true" class="text-brand-400">◷</span>
          {{ dateFull(ticket.sessionAt) }}, {{ time(ticket.sessionAt) }}
        </p>

        <p class="mt-3 border-t border-dashed border-line pt-3 font-mono text-2xs text-subtle">
          {{ ticket.code }}
        </p>
      </div>

      <!-- QR -->
      <div
        class="flex flex-col items-center justify-center gap-2 border-line border-dashed p-3 sm:w-40 sm:border-l"
      >
        <img
          v-if="qrUrl"
          :src="qrUrl"
          :alt="`QR-код билета ${ticket.code}`"
          class="h-32 w-32 rounded-lg bg-white p-1.5 sm:h-full sm:w-full sm:max-w-[128px]"
        />
        <div v-else class="skeleton h-24 w-24" />
        <p :class="cn('text-center text-2xs', used ? 'text-subtle' : 'text-brand-400')">
          {{ used ? 'Использован' : 'Покажите на входе' }}
        </p>
      </div>
    </div>
  </article>
</template>
