<script setup lang="ts">
/**
 * Билет.
 *
 * QR — главное, а не подпись под ним: на входе билет сканируют, а не читают.
 * Поэтому код крупный, с тихой зоной, и не инвертируется в тёмной теме —
 * сканеры надёжнее читают тёмное на светлом. Ряд, место и время набраны крупно:
 * это те три вещи, которые человек ищет в последние минуты перед входом.
 */
import { computed, ref, watch } from 'vue'
import QRCode from 'qrcode'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import TicketCanvas from '@/components/storefront/TicketCanvas.vue'
import { money, dateFull, time } from '@/lib/format'
import { findStatusMeta } from '@/lib/statusLabels'
import type { TicketCard as TicketCardType } from '@/lib/types'
import { cn } from '@/lib/cn'

const props = defineProps<{ ticket: TicketCardType }>()

const qrUrl = ref('')
const qrFailed = ref(false)

let qrGeneration = 0
watch(
  () => [props.ticket.status, props.ticket.qrPayload] as const,
  async ([status, payload]) => {
    const generation = ++qrGeneration
    qrUrl.value = ''
    qrFailed.value = false
    // Показываем сканируемый код только для действующего билета. QR уже
    // использованных/возвращённых/отозванных билетов может вводить в заблуждение.
    if (status !== 'issued') return
    if (!payload) {
      qrFailed.value = true
      return
    }
    try {
      const url = await QRCode.toDataURL(payload, {
        width: 320,
        margin: 1,
        errorCorrectionLevel: 'M',
        color: { dark: '#120F24', light: '#FFFFFF' },
      })
      if (generation === qrGeneration) qrUrl.value = url
    } catch {
      if (generation === qrGeneration) {
        qrUrl.value = ''
        qrFailed.value = true
      }
    }
  },
  { immediate: true },
)

const inactive = props.ticket.status !== 'issued'

/**
 * Осознанные отклонения от словаря — и ТОЛЬКО они.
 *
 * Словарь (`lib/statusLabels.ts`) обещает, что статус называется одинаково
 * везде. Здесь два случая, где «одинаково» — неправильно, и оба про читателя
 * этой подписи, а не про состояние билета:
 *
 *  - `issued` → «Покажите на входе». Подпись стоит под QR, то есть это
 *    ИНСТРУКЦИЯ. Человек уже держит билет в руках; «Действителен» ему ничего
 *    не говорит, а «покажите» — говорит.
 *  - `refunded` → «Возврат оформлен». «Возврат» — существительное-ярлык. В
 *    момент, когда человека не пускают на вход, ему важно, что возврат уже
 *    ОФОРМЛЕН, а не как называется состояние билета.
 *
 * Раньше обе строки лежали внутри копии словаря (`inactiveLabel`), вперемешку
 * с четырьмя честными копиями. Отличить осознанное отклонение от случайного
 * расхождения было нельзя — а случайное выглядело бы ровно так же
 * правдоподобно. Теперь отклонения перечислены отдельно от словаря, и всё,
 * чего здесь нет, приходит от владельца.
 */
const QR_CAPTION_OVERRIDES: Partial<Record<string, string>> = {
  issued: 'Покажите на входе',
  refunded: 'Возврат оформлен',
}

/**
 * Незнакомый статус — тоже «Покажите на входе»: сырой английский код под QR
 * бесполезен покупателю, а промолчать нельзя — подпись объясняет, что делать.
 */
const qrCaption = computed(
  () =>
    QR_CAPTION_OVERRIDES[props.ticket.status] ??
    findStatusMeta('ticket', props.ticket.status)?.label ??
    'Покажите на входе',
)
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
    <div class="p-4">
      <!-- Шапка: сектор / событие / площадка / статус — видны всегда,
           независимо от того, показываем ли мы полный макет билета. -->
      <div class="mb-3 flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <p class="text-2xs uppercase tracking-wider text-subtle">{{ ticket.sector }}</p>
          <h3 class="mt-0.5 truncate text-base font-semibold text-content">{{ ticket.eventTitle }}</h3>
          <p class="mt-0.5 truncate text-xs text-muted">{{ ticket.venue }}</p>
        </div>
        <NStatusBadge kind="ticket" :status="ticket.status" />
      </div>

      <!-- Полный макет билета (назначен мероприятию и билет действует). -->
      <TicketCanvas
        v-if="ticket.template && ticket.status === 'issued'"
        :config="ticket.template"
        :ticket="ticket"
      />

      <!-- Иначе — семантическая карточка: ряд/место/цена и отдельный QR. -->
      <div v-else class="flex flex-col gap-4 sm:flex-row">
        <div class="min-w-0 flex-1">
          <dl class="grid grid-cols-3 gap-3">
            <div>
              <dt class="text-2xs uppercase tracking-wide text-subtle">Ряд</dt>
              <dd class="text-xl font-bold tabular-nums text-content">{{ ticket.row || '—' }}</dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wide text-subtle">Место</dt>
              <dd class="text-xl font-bold tabular-nums text-content">{{ ticket.seat || '—' }}</dd>
            </div>
            <div>
              <dt class="text-2xs uppercase tracking-wide text-subtle">Цена</dt>
              <dd class="text-base font-semibold tabular-nums text-content">{{ money(ticket.priceMinor) }}</dd>
            </div>
          </dl>

          <p class="mt-3 flex items-center gap-1.5 text-sm text-content">
            <span aria-hidden="true" class="text-brand-400">◷</span>
            <template v-if="ticket.sessionAt">{{ dateFull(ticket.sessionAt) }}, {{ time(ticket.sessionAt) }}</template>
            <template v-else>Дата и время не указаны</template>
          </p>
        </div>

        <div
          class="flex flex-col items-center justify-center gap-2 border-line border-dashed p-3 sm:w-40 sm:border-l"
        >
          <img
            v-if="qrUrl"
            :src="qrUrl"
            :alt="`QR-код билета ${ticket.code}`"
            class="h-32 w-32 rounded-lg bg-white p-1.5 sm:h-full sm:w-full sm:max-w-[128px]"
          />
          <div v-else-if="ticket.status === 'issued' && !qrFailed" class="skeleton h-24 w-24" />
          <div v-else class="grid h-24 w-24 place-items-center rounded-lg bg-surface-2 text-center text-xs text-subtle">QR недоступен</div>
          <p :class="cn('text-center text-2xs', inactive ? 'text-subtle' : 'text-brand-400')">
            {{ qrCaption }}
          </p>
        </div>
      </div>

      <p class="mt-3 border-t border-dashed border-line pt-3 font-mono text-2xs text-subtle">
        {{ ticket.code }}
      </p>
    </div>
  </article>
</template>
