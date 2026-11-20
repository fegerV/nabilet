import { describe, expect, it } from 'vitest'
import { FALLBACK_MESSAGE, supportActions, telHref } from '@/lib/support'
import { defaultConfig } from '@/lib/storefront'

/** Кнопки fallback-окна: от href зависит, сможет ли покупатель дозвониться. */
describe('support fallback actions', () => {
  it('builds tel: from a formatted phone', () => {
    expect(telHref('+7 3462 25-00-00')).toBe('tel:+73462250000')
    expect(telHref('8 (999) 123-45-67')).toBe('tel:89991234567')
  })

  it('never produces an empty or malformed tel link', () => {
    expect(telHref('')).toBe('')
    expect(telHref('---')).toBe('')
    expect(telHref('abc')).toBe('')
  })

  it('creates only the actions whose contacts are configured', () => {
    const support = {
      ...defaultConfig().support,
      phone: '+7 999 123-45-67',
      telegram: 'https://t.me/boxoffice',
      whatsapp: 'https://wa.me/79991234567',
      email: 'cash@example.ru',
    }

    expect(supportActions(support).map((a) => a.kind)).toEqual([
      'call', 'telegram', 'whatsapp', 'email',
    ])
    expect(supportActions(support).map((a) => a.href)).toEqual([
      'tel:+79991234567',
      'https://t.me/boxoffice',
      'https://wa.me/79991234567',
      'mailto:cash@example.ru',
    ])
  })

  it('drops unsafe hrefs and malformed emails', () => {
    const support = {
      ...defaultConfig().support,
      phone: '',
      telegram: 'javascript:alert(1)',
      whatsapp: 'data:text/html;base64,AAAA',
      email: 'not-an-email',
    }

    expect(supportActions(support)).toEqual([])
  })

  it('works with an absent support section (old saved config)', () => {
    expect(supportActions(undefined)).toEqual([])
    expect(FALLBACK_MESSAGE.length).toBeGreaterThan(20)
  })
})
