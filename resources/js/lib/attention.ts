/**
 * Блок «Требует внимания» на обзоре админки: из чисел — в фразы.
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ МОДУЛЬ, А НЕ COMPUTED В СТРАНИЦЕ
 *   Здесь жило три бинарных копии правила склонения:
 *
 *     `${drafts} ${drafts === 1 ? 'мероприятие' : 'мероприятий'} ещё в черновиках`
 *
 *   Копия верна для 1 и для 5, поэтому выглядела правильной — а для 2, 3 и 4
 *   давала «2 мероприятий ещё в черновиках», «3 заказов ждут оплаты»,
 *   «2 неудачных платежей». Владелец правила (`format.ts::plural`) при этом
 *   существовал и не вызывался ни разу.
 *
 *   Пока фразы собирались внутри `computed` на странице, проверить их можно
 *   было только монтированием дашборда. Здесь они — чистая функция от чисел,
 *   и `tests/Unit/attention.test.ts` спрашивает ровно те числа, на которых
 *   копии врали.
 */

import { plural } from './format'

/** Что посчитала страница. Числа, а не строки: тексты — дело этого модуля. */
export interface AttentionCounts {
  /** Заказы в `awaiting_payment` старше часа. */
  staleAwaiting: number
  /** Мероприятия в `draft`. */
  drafts: number
  /** Платежи в `failed`. */
  failedPayments: number
  /** Был ли хотя бы один успешный платёж. */
  hasSucceededPayment: boolean
}

export interface AttentionTask {
  id: string
  text: string
  action: string
  to: string
  urgent: boolean
}

/**
 * Список задач. Пустой список — законный ответ: «всё в порядке».
 *
 * `urgent` — не «важно вообще», а «прямо сейчас стоит денег или витрины»:
 * просроченная оплата, неопубликованное событие, провалившийся платёж.
 */
export function attentionTasks(counts: AttentionCounts): AttentionTask[] {
  const list: AttentionTask[] = []

  if (counts.staleAwaiting > 0) {
    list.push({
      id: 'awaiting',
      text: `${counts.staleAwaiting} ${plural(
        counts.staleAwaiting,
        'заказ ждёт',
        'заказа ждут',
        'заказов ждут',
      )} оплаты больше часа`,
      action: 'Проверить',
      to: '/admin/orders',
      urgent: true,
    })
  }

  if (counts.drafts > 0) {
    list.push({
      id: 'drafts',
      text: `${counts.drafts} ${plural(
        counts.drafts,
        'мероприятие',
        'мероприятия',
        'мероприятий',
      )} ещё в черновиках`,
      action: 'Опубликовать',
      to: '/admin/events',
      urgent: true,
    })
  }

  if (counts.failedPayments > 0) {
    list.push({
      id: 'failed-payments',
      text: `${counts.failedPayments} ${plural(
        counts.failedPayments,
        'неудачный платёж',
        'неудачных платежа',
        'неудачных платежей',
      )}`,
      action: 'Разобрать',
      to: '/admin/payments',
      urgent: true,
    })
  }

  if (!counts.hasSucceededPayment) {
    list.push({
      id: 'no-payments',
      text: 'Ни одного успешного платежа — проверьте приём денег',
      action: 'Настроить',
      to: '/admin/integrations',
      urgent: false,
    })
  }

  return list
}
