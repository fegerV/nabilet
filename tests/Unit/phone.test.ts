/**
 * Валидация телефона — одно правило, один владелец.
 *
 * ПОЧЕМУ ЭТОТ ФАЙЛ ПОЯВИЛСЯ
 *   Правило «ровно 11 цифр» жило двумя буквально одинаковыми строками в
 *   `CheckoutPage.vue` и `usePurchaseFlow.ts`. Компилировалось, поэтому и не
 *   выглядело ошибкой, но копия неизбежно разошлась бы с оригиналом при правке.
 *   Теперь правило живёт в `lib/phone.ts::phoneError` и вызывается, а сторож
 *   ниже не даёт второй копии появиться в `resources/js`.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { phoneError } from '@/lib/phone'

describe('phoneError', () => {
  it('возвращает null для ровно 11 цифр (с мусором и без)', () => {
    expect(phoneError('79123456789')).toBeNull()
    expect(phoneError('+7 912 345-67-89')).toBeNull()
    expect(phoneError('8 (912) 345-67-89')).toBeNull()
    expect(phoneError('  7 912 345 67 89 ')).toBeNull()
  })

  it('возвращает сообщение при 10 цифрах', () => {
    expect(phoneError('9123456789')).toBe('Телефон — 11 цифр, начиная с 7')
  })

  it('возвращает сообщение при 12 цифрах', () => {
    expect(phoneError('912345678912')).toBe('Телефон — 11 цифр, начиная с 7')
  })

  it('возвращает сообщение для пустой и нецифровой строки', () => {
    expect(phoneError('')).toBe('Телефон — 11 цифр, начиная с 7')
    expect(phoneError('телефон')).toBe('Телефон — 11 цифр, начиная с 7')
  })
})

describe('телефон: один владелец в lib/phone.ts', () => {
  /** Комментарии выбрасываются: сторож не имеет права матчить пояснение. */
  function codeOnly(source: string): string {
    return source
      .replace(/\/\*[\s\S]*?\*\//g, '')
      .replace(/<!--[\s\S]*?-->/g, '')
      .replace(/\/\/[^\n]*/g, '')
  }

  function sourceFiles(dir: string): string[] {
    const found: string[] = []

    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name)

      if (entry.isDirectory()) {
        found.push(...sourceFiles(full))
        continue
      }

      if (entry.name.endsWith('.ts') || entry.name.endsWith('.vue')) {
        found.push(full)
      }
    }

    return found
  }

  it('нигде больше нет копии правила', () => {
    // Вернуть копию (свой `phoneError`, сырой `replace(/\\D/g,'').length !== 11`
    // или текст ошибки «Телефон — 11 цифр…») — упадёт по имени файла: правило
    // живёт только в `lib/phone.ts`.
    const root = join(process.cwd(), 'resources', 'js')
    const offenders: string[] = []
    const RAW_CHECK = /replace\(\/\\D\/g,\s*''\)\.length\s*!==\s*11/
    const RAW_MSG = /Телефон — 11 цифр, начиная с 7/

    for (const file of sourceFiles(root)) {
      const relative = file.slice(root.length + 1).replace(/\\/g, '/')
      if (relative === 'lib/phone.ts') continue
      const src = codeOnly(readFileSync(file, 'utf8'))
      if (RAW_CHECK.test(src) || RAW_MSG.test(src)) offenders.push(relative)
    }

    expect(offenders, offenders.join('\n')).toEqual([])
  })
})
