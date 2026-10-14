/**
 * `lib/referral` — атрибуция приглашения (фаза 4).
 *
 * ЗДЕСЬ ПРОВЕРЯЮТСЯ ПРАВИЛА ФОРМЫ, А НЕ СЕТЬ
 *   Нормализация и алфавит — зеркало `Domain\ReferralCode`: расхождение
 *   означало бы «витрина запомнила код, которого сервер не признает». Сеть
 *   замокана так же, как в соседних тестах (`loyaltyLevel.test.ts`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  REFERRAL_ALPHABET,
  fetchReferral,
  isWellFormedReferralCode,
  normalizeReferralCode,
  rememberedReferralCode,
  rememberReferralCode,
  clearRememberedReferralCode,
} from '@/lib/referral'

/** Действительный код: 16 символов алфавита. */
const CODE = 'ABCDEFGHJKLMNPQR'

function jsonResponse(body: unknown, init: { status?: number } = {}): Response {
  const status = init.status ?? 200
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(),
    json: async () => body,
  } as unknown as Response
}

beforeEach(() => {
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('lib/referral: форма кода', () => {
  it('нормализует обрезкой и верхним регистром', () => {
    expect(normalizeReferralCode(`  ${CODE.toLowerCase()} \n`)).toBe(CODE)
    expect(normalizeReferralCode(CODE)).toBe(CODE)
  })

  it('принимает код своей длины и алфавита', () => {
    expect(isWellFormedReferralCode(CODE)).toBe(true)
  })

  it('отвергает чужую длину', () => {
    expect(isWellFormedReferralCode(CODE.slice(0, 15))).toBe(false)
    expect(isWellFormedReferralCode(`${CODE}S`)).toBe(false)
  })

  it('отвергает символы вне алфавита: 0/O, 1/I и кириллица', () => {
    // Ровно те пары, которые исключены на сервере (`ReferralCode::ALPHABET`).
    for (const glyph of ['0', '1', 'O', 'I', 'А', 'Я']) {
      expect(REFERRAL_ALPHABET.includes(glyph)).toBe(false)
      expect(isWellFormedReferralCode(`${glyph}BCDEFGHJKLMNPQ`)).toBe(false)
    }
  })

  it('отвергает нижний регистр: в ссылке хранится верхний', () => {
    expect(isWellFormedReferralCode(CODE.toLowerCase())).toBe(false)
  })
})

describe('lib/referral: атрибуция', () => {
  it('запоминает нормализованный код', () => {
    expect(rememberReferralCode(` ${CODE.toLowerCase()} `)).toBe(CODE)
    expect(rememberedReferralCode()).toBe(CODE)
  })

  it('не хранит заведомо битый код', () => {
    expect(rememberReferralCode('не-код')).toBeNull()
    expect(rememberReferralCode('')).toBeNull()
    expect(rememberReferralCode(undefined)).toBeNull()
    expect(rememberedReferralCode()).toBeNull()
  })

  it('не хранит короткий код: он не найдётся на сервере', () => {
    expect(rememberReferralCode('ABCD')).toBeNull()
    expect(rememberedReferralCode()).toBeNull()
  })

  it('забывает код по запросу — второй заказ его не тащит', () => {
    rememberReferralCode(CODE)
    clearRememberedReferralCode()
    expect(rememberedReferralCode()).toBeNull()
  })
})

describe('lib/referral: fetchReferral', () => {
  it('читает /me/referral и приводит суммы к целым', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          data: {
            code: CODE,
            link: `https://n.example/#/r/${CODE}`,
            enabled: true,
            inviter_bonus: 30000,
            invited_bonus: 30000,
            stats: { invited: 3, rewarded: 2, earned: 40000 },
          },
        }),
      ),
    )

    const info = await fetchReferral()

    expect(info.code).toBe(CODE)
    expect(info.link).toBe(`https://n.example/#/r/${CODE}`)
    expect(info.enabled).toBe(true)
    expect(info.stats).toEqual({ invited: 3, rewarded: 2, earned: 40000 })
  })

  it('мусорные числа превращает в ноль, а не в NaN', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          data: {
            code: CODE,
            link: 'https://n.example/#/r/' + CODE,
            enabled: false,
            inviter_bonus: null,
            invited_bonus: 'abc',
            stats: {},
          },
        }),
      ),
    )

    const info = await fetchReferral()

    expect(info.inviter_bonus).toBe(0)
    expect(info.invited_bonus).toBe(0)
    expect(info.enabled).toBe(false)
    expect(info.stats).toEqual({ invited: 0, rewarded: 0, earned: 0 })
  })
})
