/**
 * Списание бонусов в чекауте (фаза 2b).
 *
 * ЧТО ЗДЕСЬ ПРОВЕРЯЕТСЯ И ПОЧЕМУ ЭТО НЕ «ТЕСТ ВЁРСТКИ»
 *   1. ЛИМИТ. Бонусы — это скидка, а не второй способ платить, поэтому
 *      списывается меньшее из трёх: 30 % от подытога, остаток после скидки и
 *      баланс. Ошибка здесь — это подарок за счёт организатора (списали больше
 *      баланса) или заказ, закрытый бонусами целиком.
 *   2. ЗЕРКАЛО СЕРВЕРНОЙ ФОРМУЛЫ. `spendCapMinor()` считает лимит на клиенте,
 *      чтобы показать его покупателю, а чекаут считает тот же лимит заново.
 *      Тесты фиксируют не только «30 % от 1000 ₽», но и границу округления
 *      (33 333 → 10 000, а не 9 999): именно на копейке клиентский лимит и
 *      серверный разошлись бы, и покупатель получил бы отказ на ровном месте.
 *   3. ГОСТЬ БЛОКА НЕ ВИДИТ. `loyalty.bonus === null` — «не знаю», а не «ноль»:
 *      предлагать списать бонусы тому, у кого их нет, значит обещать пустоту.
 *   4. ЧТО УХОДИТ НА СЕРВЕР. Галочка обязана попадать в тело чекаута суммой в
 *      МИНОРНЫХ единицах (`bonus_amount`), а снятая — нулём. Проверяется сам
 *      вызов `checkoutSession`, потому что «посчитали и не отправили» — это
 *      ровно тот дефект, который не видно ни в одном из остальных тестов.
 *
 * ПОЧЕМУ СУММЫ ЧИТАЮТСЯ ИЗ `dd`, А НЕ ИЗ ТЕКСТА СТРАНИЦЫ
 *   `wrapper.text()` склеивает соседние узлы без пробелов («Итого1 000 ₽»), и
 *   проверка превратилась бы в проверку того, как Vue склеивает текст. Строки
 *   итога — это `dd` внутри `dl`, и они читаются по порядку: подытог, бонусы
 *   (когда они есть), итог.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import { useCartStore } from '@/stores/cart'
import { useLoyaltyStore } from '@/stores/loyalty'
import CheckoutPage from '@/pages/storefront/CheckoutPage.vue'

/* Моки на уровне модулей: `pay()` уходит в платёжный провайдер, а нам нужен
 * только факт вызова чекаута с нужным телом. */
const checkoutSpy = vi.hoisted(() => vi.fn())
const paySpy = vi.hoisted(() => vi.fn())

vi.mock('@/lib/inventory', () => ({
  fetchCart: vi.fn(async () => null),
  checkoutSession: checkoutSpy,
}))

vi.mock('@/lib/payments', () => ({
  initiatePayment: paySpy,
  anchorDemoUrl: (url: string | null) => url,
  newIdempotencyKey: () => 'idem-key',
  rememberPaymentId: vi.fn(),
}))

vi.mock('@/lib/metrika', () => ({ trackEvent: vi.fn(async () => {}) }))

/**
 * Текст без неразрывных пробелов.
 *
 * `Intl.NumberFormat('ru-RU')` разделяет тысячи U+00A0, и `toContain('1 000 ₽')`
 * с обычным пробелом не нашёл бы ничего — тест падал бы на форматировании, а не
 * на логике.
 */
function norm(value: string): string {
  return value.replace(/[\u00A0\u202F]/g, ' ')
}

/** Строки итога по порядку: подытог, [бонусы], итог. */
function totalRows(wrapper: VueWrapper): string[] {
  return wrapper.findAll('dl dd').map((el) => norm(el.text()))
}

interface MountOptions {
  /** Баланс в минорных единицах; `null` — счёт не загружен (гость). */
  balance?: number | null
  /** Сумма позиций в минорных единицах. */
  subtotalMinor?: number
}

/** Страница оформления с корзиной из одного места и заданным балансом. */
async function mountPage({ balance = 50000, subtotalMinor = 100000 }: MountOptions = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const cart = useCartStore()
  cart.seats = [
    { id: 'inv-1', sector: 'Партер', row: 3, number: 12, priceMinor: subtotalMinor, kind: 'standard' },
  ]
  cart.selectedIds = new Set(['inv-1'])

  const loyalty = useLoyaltyStore()
  loyalty.bonus =
    balance === null ? null : { balance, bonuses: Math.floor(balance / 100), currency: 'RUB' }

  // `session` в query обязателен: без него `pay()` честно отказывается работать
  // («сеанс не определён») и до чекаута дело не доходит — тест «что уходит на
  // сервер» проверял бы ветку отказа вместо ветки покупки.
  const router = createRouter({
    history: createWebHashHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
  await router.push({ path: '/', query: { session: '1' } })
  await router.isReady()

  return {
    cart,
    loyalty,
    wrapper: mount(CheckoutPage as never, { global: { plugins: [pinia, router] } }),
  }
}

/** Заполнить форму так, чтобы `canPay` стал истинным. */
async function fillForm(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('input[autocomplete="name"]').setValue('Иван Петров')
  await wrapper.find('input[autocomplete="tel"]').setValue('+7 900 123-45-67')

  const emails = wrapper.findAll('input[autocomplete="email"]')
  await emails[0].setValue('ivan@example.com')
  await emails[1].setValue('ivan@example.com')

  // Первая галочка в разметке — согласие с офертой (левая колонка идёт раньше
  // итога), вторая — бонусы.
  await wrapper.find('input[type="checkbox"]').setValue(true)
}

/** Галочка «списать бонусы» — вторая в разметке. */
function bonusCheckbox(wrapper: VueWrapper) {
  return wrapper.findAll('input[type="checkbox"]')[1]
}

beforeEach(() => {
  localStorage.clear()
  vi.unstubAllGlobals()
  checkoutSpy.mockReset()
  paySpy.mockReset()
  // Ссылки на оплату нет — `pay()` не уходит навигацией на провайдера.
  paySpy.mockResolvedValue({ confirmation_url: null, payment: null })
})

describe('CheckoutPage: блок бонусов', () => {
  it('гость блока не видит: `null` — это «не знаю», а не «ноль»', async () => {
    const { wrapper } = await mountPage({ balance: null })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1) // только оферта
    expect(totalRows(wrapper)).toEqual(['1 000 ₽', '1 000 ₽'])
  })

  it('пустой счёт блока не даёт: предлагать нечего', async () => {
    const { wrapper } = await mountPage({ balance: 0 })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1)
  })

  it('нулевой лимит блока не даёт, даже когда баланс есть', async () => {
    // Заказ на 1 копейку: 30 % от него округляются до нуля. Списывать нечего,
    // и галочка «списать 0 бонусов» была бы шумом.
    const { wrapper } = await mountPage({ subtotalMinor: 1, balance: 50000 })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1)
  })

  it('называет лимит в бонусах и показывает остаток', async () => {
    // 1000 ₽ заказ, 500 бонусов на счёте → лимит 30 % = 300 бонусов.
    const { wrapper } = await mountPage({ subtotalMinor: 100000, balance: 50000 })
    await flushPromises()

    const t = norm(wrapper.text())
    expect(t).toContain('Списать 300 бонусов')
    expect(t).toContain('−300 ₽')
    expect(t).toContain('останется 200 бонусов')
  })

  it('лимит ограничен балансом, когда баланс меньше 30 %', async () => {
    // 1000 ₽ заказ, 120 бонусов на счёте → списываем 120, а не 300.
    const { wrapper } = await mountPage({ subtotalMinor: 100000, balance: 12000 })
    await flushPromises()

    const t = norm(wrapper.text())
    expect(t).toContain('Списать 120 бонусов')
    expect(t).toContain('останется 0 бонусов')
  })

  it('галочка уменьшает итог и добавляет строку «Бонусами»', async () => {
    const { wrapper } = await mountPage({ subtotalMinor: 100000, balance: 50000 })
    await flushPromises()

    expect(totalRows(wrapper)).toEqual(['1 000 ₽', '1 000 ₽'])

    await bonusCheckbox(wrapper).setValue(true)

    expect(totalRows(wrapper)).toEqual(['1 000 ₽', '−300 ₽', '700 ₽'])
    // Кнопка обещает ту же сумму, что и итог: расхождение между ними — это
    // «списали не то, что показали».
    expect(norm(wrapper.find('button').text())).toContain('Оплатить 700 ₽')
  })

  it('снятая галочка возвращает полный итог', async () => {
    const { wrapper } = await mountPage({ subtotalMinor: 100000, balance: 50000 })
    await flushPromises()

    await bonusCheckbox(wrapper).setValue(true)
    await bonusCheckbox(wrapper).setValue(false)

    expect(totalRows(wrapper)).toEqual(['1 000 ₽', '1 000 ₽'])
  })
})

describe('CheckoutPage: что уходит на сервер', () => {
  it('без галочки бонусы не списываются', async () => {
    checkoutSpy.mockResolvedValue({ data: { order_id: 'ORD1', total_amount: 100000 } })

    const { wrapper } = await mountPage({ subtotalMinor: 100000, balance: 50000 })
    await flushPromises()
    await fillForm(wrapper)
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(checkoutSpy).toHaveBeenCalledTimes(1)
    // Ноль, а не отсутствие поля: явный ноль читается однозначно и на сервере,
    // и в логах запроса.
    expect(checkoutSpy.mock.calls[0][1]).toMatchObject({ bonus_amount: 0 })
  })

  it('галочка отправляет лимит в МИНОРНЫХ единицах', async () => {
    checkoutSpy.mockResolvedValue({ data: { order_id: 'ORD1', total_amount: 70000 } })

    const { wrapper } = await mountPage({ subtotalMinor: 100000, balance: 50000 })
    await flushPromises()
    await fillForm(wrapper)
    await bonusCheckbox(wrapper).setValue(true)
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(checkoutSpy).toHaveBeenCalledTimes(1)
    // 300 бонусов — это 30 000 минорных единиц. Отправка «300» списала бы
    // 300 копеек вместо 300 рублей — расхождение в 100 раз.
    expect(checkoutSpy.mock.calls[0][1]).toMatchObject({ bonus_amount: 30000 })
  })
})

describe('spendCapMinor: зеркало BonusPolicy', () => {
  it('30 % от подытога', async () => {
    const { spendCapMinor } = await import('@/lib/loyalty')
    expect(spendCapMinor(100000, 0, 500000)).toBe(30000)
  })

  it('ограничен балансом', async () => {
    const { spendCapMinor } = await import('@/lib/loyalty')
    expect(spendCapMinor(100000, 0, 12000)).toBe(12000)
  })

  it('ограничен остатком после скидки', async () => {
    const { spendCapMinor } = await import('@/lib/loyalty')
    // 90 % скидки: остаётся 10 000, и бонусы не могут увести итог в минус.
    expect(spendCapMinor(100000, 90000, 500000)).toBe(10000)
  })

  it('ноль, когда скидка закрывает заказ целиком', async () => {
    const { spendCapMinor } = await import('@/lib/loyalty')
    expect(spendCapMinor(100000, 100000, 500000)).toBe(0)
  })

  it('округляет половину вверх — как Money::percentage на сервере', async () => {
    const { spendCapMinor } = await import('@/lib/loyalty')
    // 33 333 × 30 % = 9 999,9 → 10 000. Округление вниз дало бы 9 999, и
    // покупатель получил бы 422 на сумму, которую экран показал как доступную.
    expect(spendCapMinor(33333, 0, 500000)).toBe(10000)
    // Ровно половина: 35 × 30 % = 10,5 → 11.
    expect(spendCapMinor(35, 0, 500000)).toBe(11)
  })

  it('отрицательный баланс — ноль, а не отрицательный лимит', async () => {
    const { spendCapMinor } = await import('@/lib/loyalty')
    expect(spendCapMinor(100000, 0, -500)).toBe(0)
  })
})
