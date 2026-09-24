<script setup lang="ts">
/**
 * Статус сущности.
 *
 * Здесь заканчивается свобода дизайнера: подписи и цвета привязаны к машинам
 * состояний из docs/STATE-MACHINES.md. Если статус заказа «awaiting_payment»
 * — он везде «Ждём оплаты» и везде одного цвета: в таблице заказов, в шапке
 * деталки, в письме. Пользователь учит систему один раз.
 */
import NBadge from './NBadge.vue'
import type { EventStatus, OrderStatus, TicketStatus, Tone } from '@/lib/types'

const props = defineProps<{
  kind: 'order' | 'ticket' | 'event' | 'session'
  status: OrderStatus | TicketStatus | EventStatus | string
  size?: 'sm' | 'md'
}>()

const ORDER: Record<OrderStatus, { label: string; tone: Tone; dot: boolean }> = {
  pending: { label: 'Создан', tone: 'neutral', dot: true },
  awaiting_payment: { label: 'Ждём оплаты', tone: 'sun', dot: true },
  paid: { label: 'Оплачен', tone: 'mint', dot: false },
  payment_failed: { label: 'Оплата не прошла', tone: 'rose', dot: false },
  cancelled: { label: 'Отменён', tone: 'neutral', dot: false },
  expired: { label: 'Истёк', tone: 'neutral', dot: false },
  partially_refunded: { label: 'Частичный возврат', tone: 'sun', dot: false },
  refunded: { label: 'Возврат', tone: 'rose', dot: false },
}

const TICKET: Record<TicketStatus, { label: string; tone: Tone; dot: boolean }> = {
  issued: { label: 'Действителен', tone: 'mint', dot: true },
  used: { label: 'Использован', tone: 'brand', dot: false },
  cancelled: { label: 'Отменён', tone: 'neutral', dot: false },
  refunded: { label: 'Возврат', tone: 'rose', dot: false },
  expired: { label: 'Истёк', tone: 'neutral', dot: false },
}

const EVENT: Record<EventStatus, { label: string; tone: Tone; dot: boolean }> = {
  draft: { label: 'Черновик', tone: 'neutral', dot: false },
  published: { label: 'В продаже', tone: 'mint', dot: true },
  sold_out: { label: 'Билетов нет', tone: 'accent', dot: false },
  finished: { label: 'Завершён', tone: 'neutral', dot: false },
  cancelled: { label: 'Отменён', tone: 'rose', dot: false },
}

const SESSION: Record<string, { label: string; tone: Tone; dot: boolean }> = {
  scheduled: { label: 'Запланирован', tone: 'neutral', dot: true },
  on_sale: { label: 'В продаже', tone: 'mint', dot: true },
  held: { label: 'Открыт', tone: 'brand', dot: false },
  completed: { label: 'Завершён', tone: 'neutral', dot: false },
  cancelled: { label: 'Отменён', tone: 'rose', dot: false },
}

/** Разные сущности — разные наборы статусов, поэтому выбор идёт по типу. */
const meta =
  props.kind === 'order'
    ? ORDER[props.status as OrderStatus]
    : props.kind === 'ticket'
      ? TICKET[props.status as TicketStatus]
      : props.kind === 'session'
        ? SESSION[props.status as string]
        : EVENT[props.status as EventStatus]
</script>

<template>
  <NBadge :tone="meta.tone" :dot="meta.dot" :size="size ?? 'sm'">{{ meta.label }}</NBadge>
</template>
