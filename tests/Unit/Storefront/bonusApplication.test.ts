/**
 * Применение бонусов в чекауте — ОДНО правило, ОДИН владелец.
 *
 * ПОЧЕМУ ЭТОТ ФАЙЛ ПОЯВИЛСЯ
 *   `applyBonuses()` в `lib/loyalty.ts` — единственное место, где считаются
 *   пять величин списания (лимит, применённая сумма, итог, лимит в бонусах,
 *   остаток). Раньше те же пять чисел считались ДВАЖДЫ — в `usePurchaseFlow`
 *   (предпросмотр на витрине) и в `CheckoutPage` (там же и отправка
 *   `bonus_amount`). Формулы были побайтово равны, поэтому расхождения не
 *   было, но копия несла в себе именно ту беду, от которой её писали
 *   защитить: стоило поменять лимит в одном месте, как вторая страница
 *   продолжила бы показывать старый итог, а сервер вернул бы 422 на новый.
 *
 * ЧТО ЗДЕСЬ СТОРОЖИТСЯ
 *   1. Таблица арифметики `applyBonuses()` — пять выходов на семи наборах
 *      входов (нулевой баланс, 30%-лимит, лимит по балансу, потолок скидки,
 *      скидка больше подытога, галочка снята, итог меньше лимита).
 *   2. Статический сторож: ни в `usePurchaseFlow.ts`, ни в `CheckoutPage.vue`
 *      нет ЖИВОГО вызова `spendCapMinor(...)` и нет `MINOR_UNITS_PER_BONUS` —
 *      обе страницы вызывают только `applyBonuses()`. Верни одну страницу к
 *      рукописной копии, и этот сторож упадёт по имени файла, не дав копии
 *      поселиться снова. Это и есть доказательство непустоты правила.
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { applyBonuses, type BonusApplicationInput } from '@/lib/loyalty'

/** Убрать комментарии, чтобы сторож смотрел на код, а не на пояснения. */
function stripComments(source: string): string {
  // Блочные комментарии сначала (могут пересекать строки), затем строчные.
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
}

/** Прочитать файл страницы и снять с него комментарии. */
function pageSource(relPath: string): string {
  return stripComments(readFileSync(join(process.cwd(), relPath), 'utf8'))
}

describe('applyBonuses: арифметика пяти величин', () => {
  const CASES: Array<[string, BonusApplicationInput, ReturnType<typeof applyBonuses>]> = [
    [
      'нулевой баланс — списывать нечего, даже с галочкой',
      { subtotalMinor: 10000, discountMinor: 0, balanceMinor: 0, cartTotalMinor: 10000, useBonuses: true },
      { capMinor: 0, appliedMinor: 0, totalMinor: 10000, capUnits: 0, leftUnits: 0 },
    ],
    [
      '30%-лимит при полном балансе — списываем ровно 30 ₽ из 100 ₽',
      { subtotalMinor: 10000, discountMinor: 0, balanceMinor: 10000, cartTotalMinor: 10000, useBonuses: true },
      { capMinor: 3000, appliedMinor: 3000, totalMinor: 7000, capUnits: 30, leftUnits: 70 },
    ],
    [
      'лимит по балансу — баланса меньше 30%, списываем весь',
      { subtotalMinor: 100000, discountMinor: 0, balanceMinor: 1000, cartTotalMinor: 100000, useBonuses: true },
      { capMinor: 1000, appliedMinor: 1000, totalMinor: 99000, capUnits: 10, leftUnits: 0 },
    ],
    [
      'скидка съедает потолок — лимит ограничен остатком после скидки',
      { subtotalMinor: 10000, discountMinor: 9000, balanceMinor: 10000, cartTotalMinor: 1000, useBonuses: true },
      { capMinor: 1000, appliedMinor: 1000, totalMinor: 0, capUnits: 10, leftUnits: 90 },
    ],
    [
      'скидка больше подытога — потолок ноль, бонусы не уводят итог в минус',
      { subtotalMinor: 10000, discountMinor: 12000, balanceMinor: 5000, cartTotalMinor: 10000, useBonuses: true },
      { capMinor: 0, appliedMinor: 0, totalMinor: 10000, capUnits: 0, leftUnits: 50 },
    ],
    [
      'галочка снята — лимит посчитан, но списано ноль, итог равен корзине',
      { subtotalMinor: 10000, discountMinor: 0, balanceMinor: 10000, cartTotalMinor: 10000, useBonuses: false },
      { capMinor: 3000, appliedMinor: 0, totalMinor: 10000, capUnits: 30, leftUnits: 70 },
    ],
    [
      'итог меньше лимита — применённая сумма не уводит итог ниже нуля',
      { subtotalMinor: 10000, discountMinor: 0, balanceMinor: 10000, cartTotalMinor: 500, useBonuses: true },
      { capMinor: 3000, appliedMinor: 3000, totalMinor: 0, capUnits: 30, leftUnits: 70 },
    ],
  ]

  for (const [name, input, expected] of CASES) {
    it(name, () => {
      expect(applyBonuses(input)).toEqual(expected)
    })
  }

  it('списание не может превысить баланс ни в каком наборе входов', () => {
    const inputs: BonusApplicationInput[] = [
      { subtotalMinor: 10000, discountMinor: 0, balanceMinor: 500, cartTotalMinor: 10000, useBonuses: true },
      { subtotalMinor: 100000, discountMinor: 0, balanceMinor: 100, cartTotalMinor: 100000, useBonuses: true },
      { subtotalMinor: 10000, discountMinor: 9000, balanceMinor: 50, cartTotalMinor: 1000, useBonuses: true },
    ]

    for (const input of inputs) {
      const result = applyBonuses(input)
      expect(result.appliedMinor).toBeLessThanOrEqual(input.balanceMinor)
      expect(result.leftUnits).toBeGreaterThanOrEqual(0)
    }
  })
})

describe('applyBonuses: единственный владелец правила (статический сторож)', () => {
  const pages = [
    'resources/js/pages/storefront/usePurchaseFlow.ts',
    'resources/js/pages/storefront/CheckoutPage.vue',
  ]

  for (const page of pages) {
    it(`${page} вызывает только applyBonuses(), а не spendCapMinor() напрямую`, () => {
      const code = pageSource(page)

      // Страница обязана пользоваться владельцем правила.
      expect(code, `${page} должен вызывать applyBonuses()`).toContain('applyBonuses')

      // …и не должна знать формулу лимита сама. Живой вызов spendCapMinor(...)
      // или прямое обращение к MINOR_UNITS_PER_BONUS означают, что копия
      // вернулась: этот сторож упадёт по имени файла.
      expect(code, `${page} не должен вызывать spendCapMinor() напрямую`).not.toContain('spendCapMinor')
      expect(code, `${page} не должен ссылаться на MINOR_UNITS_PER_BONUS`).not.toContain('MINOR_UNITS_PER_BONUS')
    })
  }
})
