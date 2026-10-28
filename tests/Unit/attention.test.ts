/**
 * Блок «Требует внимания» на обзоре админки — числа в фразы.
 *
 * ПОЧЕМУ ЭТОТ ФАЙЛ ВООБЩЕ ПОЯВИЛСЯ
 *   Фразы собирались прямо в `computed` на странице, и в них стояли три
 *   бинарных копии правила склонения. Копия верна для 1 и для 5 — а для 2, 3 и
 *   4 обзор писал «2 мероприятий ещё в черновиках», «3 заказов ждут оплаты»,
 *   «2 неудачных платежей». Проверить это можно было только монтированием
 *   дашборда с подготовленными заказами, платежами и событиями.
 *
 *   Теперь тексты — чистая функция от чисел (`lib/attention.ts`), и тест
 *   спрашивает РОВНО те числа, на которых копии врали: 2, 3, 4. Числа 1 и 5
 *   тоже проверяются — именно потому, что на них копия выглядела правильно и
 *   поэтому дожила до продакшена.
 */
import { describe, expect, it } from 'vitest'
import { attentionTasks, type AttentionCounts } from '@/lib/attention'

/** Ничего не требует внимания: пустой список — законный ответ. */
function calm(): AttentionCounts {
  return { staleAwaiting: 0, drafts: 0, failedPayments: 0, hasSucceededPayment: true }
}

function textOf(counts: AttentionCounts, id: string): string | undefined {
  return attentionTasks(counts).find((task) => task.id === id)?.text
}

describe('attentionTasks: черновики', () => {
  it('склоняет слово «мероприятие» на всех формах', () => {
    const cases: Array<[number, string]> = [
      [1, '1 мероприятие ещё в черновиках'],
      [2, '2 мероприятия ещё в черновиках'],
      [3, '3 мероприятия ещё в черновиках'],
      [4, '4 мероприятия ещё в черновиках'],
      [5, '5 мероприятий ещё в черновиках'],
      [11, '11 мероприятий ещё в черновиках'],
      [21, '21 мероприятие ещё в черновиках'],
    ]

    for (const [drafts, expected] of cases) {
      expect(textOf({ ...calm(), drafts }, 'drafts'), `drafts=${drafts}`).toBe(expected)
    }
  })
})

describe('attentionTasks: просроченные оплаты', () => {
  it('склоняет «заказ ждёт / заказа ждут / заказов ждут»', () => {
    const cases: Array<[number, string]> = [
      [1, '1 заказ ждёт оплаты больше часа'],
      [2, '2 заказа ждут оплаты больше часа'],
      [4, '4 заказа ждут оплаты больше часа'],
      [5, '5 заказов ждут оплаты больше часа'],
      [12, '12 заказов ждут оплаты больше часа'],
      [22, '22 заказа ждут оплаты больше часа'],
    ]

    for (const [staleAwaiting, expected] of cases) {
      expect(textOf({ ...calm(), staleAwaiting }, 'awaiting'), `staleAwaiting=${staleAwaiting}`).toBe(expected)
    }
  })
})

describe('attentionTasks: неудачные платежи', () => {
  it('склоняет «платёж / платежа / платежей»', () => {
    const cases: Array<[number, string]> = [
      [1, '1 неудачный платёж'],
      [2, '2 неудачных платежа'],
      [3, '3 неудачных платежа'],
      [5, '5 неудачных платежей'],
      [11, '11 неудачных платежей'],
      [23, '23 неудачных платежа'],
    ]

    for (const [failedPayments, expected] of cases) {
      expect(textOf({ ...calm(), failedPayments }, 'failed-payments'), `failedPayments=${failedPayments}`).toBe(expected)
    }
  })
})

describe('attentionTasks: состав и приоритет', () => {
  it('ничего не требует внимания — пустой список', () => {
    expect(attentionTasks(calm())).toEqual([])
  })

  it('отсутствие успешных платежей — задача, но не срочная', () => {
    const tasks = attentionTasks({ ...calm(), hasSucceededPayment: false })

    expect(tasks).toHaveLength(1)
    expect(tasks[0].id).toBe('no-payments')
    expect(tasks[0].urgent).toBe(false)
  })

  it('срочные задачи идут раньше несрочной', () => {
    const tasks = attentionTasks({
      staleAwaiting: 2,
      drafts: 3,
      failedPayments: 4,
      hasSucceededPayment: false,
    })

    expect(tasks.map((t) => t.id)).toEqual(['awaiting', 'drafts', 'failed-payments', 'no-payments'])
    expect(tasks.filter((t) => t.urgent)).toHaveLength(3)
  })

  it('каждая задача ведёт в свой раздел админки', () => {
    const tasks = attentionTasks({
      staleAwaiting: 1,
      drafts: 1,
      failedPayments: 1,
      hasSucceededPayment: false,
    })

    for (const task of tasks) {
      expect(task.to.startsWith('/admin/'), `${task.id} → ${task.to}`).toBe(true)
    }
  })
})
