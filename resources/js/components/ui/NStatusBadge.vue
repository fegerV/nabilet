<script setup lang="ts">
/**
 * Статус сущности.
 *
 * Здесь заканчивается свобода дизайнера: подписи и цвета привязаны к машинам
 * состояний из docs/STATE-MACHINES.md. Если статус заказа «awaiting_payment»
 * — он везде «Ждём оплаты» и везде одного цвета: в таблице заказов, в шапке
 * деталки, в письме. Пользователь учит систему один раз.
 *
 * ПОДПИСЬ СЧИТАЕТСЯ РЕАКТИВНО. Раньше `resolved` и `meta` были обычными
 * константами, посчитанными один раз в `setup()`: компонент показывал статус
 * на момент СОЗДАНИЯ и больше никогда его не пересчитывал. В таблицах это не
 * было заметно (строка монтируется уже со своим статусом), а на форме
 * мероприятия стало видно сразу: после нажатия «Готова к продаже» событие
 * переходило в `published`, дата публикации подставлялась, а бейдж продолжал
 * писать «Черновик». То есть экран утверждал, что публикация не прошла, ровно
 * в тот момент, когда она прошла.
 */
import { computed } from 'vue'
import NBadge from './NBadge.vue'
import type { EventStatus, OrderStatus, TicketStatus, Tone } from '@/lib/types'

const props = defineProps<{
  kind: 'order' | 'ticket' | 'event' | 'session' | 'hall' | 'venue' | 'payment' | 'user'
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
  revoked: { label: 'Отозван', tone: 'rose', dot: false },
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

const HALL: Record<string, { label: string; tone: Tone; dot: boolean }> = {
  active: { label: 'Активен', tone: 'mint', dot: true },
  inactive: { label: 'Неактивен', tone: 'neutral', dot: false },
}

/** Площадка использует ту же машину состояний, что и зал (active/inactive). */
const VENUE = HALL

/**
 * Платёж: статусы провайдера (см. Payment::$status). «succeeded» и «pending»
 * — самые частые, и путать их нельзя: по «pending» деньги ещё не пришли,
 * хотя покупатель мог уже увидеть экран успеха.
 */
const PAYMENT: Record<string, { label: string; tone: Tone; dot: boolean }> = {
  pending: { label: 'В обработке', tone: 'sun', dot: true },
  waiting_for_capture: { label: 'Ждёт подтверждения', tone: 'sun', dot: true },
  succeeded: { label: 'Оплачен', tone: 'mint', dot: false },
  canceled: { label: 'Отменён', tone: 'neutral', dot: false },
  cancelled: { label: 'Отменён', tone: 'neutral', dot: false },
  failed: { label: 'Ошибка', tone: 'rose', dot: false },
  refunded: { label: 'Возврат', tone: 'rose', dot: false },
  partially_refunded: { label: 'Частичный возврат', tone: 'sun', dot: false },
}

/** Пользователь: active/inactive/banned из `users.status`. */
const USER: Record<string, { label: string; tone: Tone; dot: boolean }> = {
  active: { label: 'Активен', tone: 'mint', dot: true },
  inactive: { label: 'Неактивен', tone: 'neutral', dot: false },
  banned: { label: 'Заблокирован', tone: 'rose', dot: false },
}

/**
 * Разные сущности — разные наборы статусов, поэтому выбор идёт по типу.
 *
 * `computed`, а не `const`: статус приходит пропом и меняется на живом экране
 * (публикация мероприятия, отмена заказа). Обычная константа зафиксировала бы
 * первый отрисованный статус навсегда.
 *
 * Неизвестный статус (новый провайдер, ручная правка БД) не должен ронять
 * страницу: показываем сам код — администратору он всё равно полезнее,
 * чем пустая ячейка.
 */
const meta = computed(() => {
  const resolved =
    props.kind === 'order'
      ? ORDER[props.status as OrderStatus]
      : props.kind === 'ticket'
        ? TICKET[props.status as TicketStatus]
        : props.kind === 'session'
          ? SESSION[props.status as string]
          : props.kind === 'hall'
            ? HALL[props.status as string]
            : props.kind === 'venue'
              ? VENUE[props.status as string]
              : props.kind === 'payment'
                ? PAYMENT[props.status as string]
                : props.kind === 'user'
                  ? USER[props.status as string]
                  : EVENT[props.status as EventStatus]

  return resolved ?? { label: props.status || '—', tone: 'neutral' as Tone, dot: false }
})
</script>

<template>
  <NBadge :tone="meta.tone" :dot="meta.dot" :size="size ?? 'sm'">{{ meta.label }}</NBadge>
</template>
