/**
 * Конверсия рублей в копейки и обратно — одно правило, один владелец.
 *
 * ПОЧЕМУ ЭТОТ ФАЙЛ ВООБЩЕ ПОЯВИЛСЯ
 *   Деньги в проекте целые и в minor units, а правит их человек в рублях, так
 *   что деление на 100 — не «мелочь форматирования», а точка, где ошибка стоит
 *   денег. Копий было четырнадцать в шести написаниях, и одна из них поделила
 *   на 100 ДВАЖДЫ: поле «Цена всех выбранных, ₽» в редакторе зала показывало
 *   цену места в 100 раз меньше — место за 1500 ₽ выглядело как 15 ₽. Ни один
 *   тест этого не видел, потому что рядом стояла такая же строка БЕЗ ошибки, а
 *   переменная называлась `groupSeatPriceRub` — то есть уже в рублях, и
 *   «поделить ещё раз» читалось как правка формата.
 *
 * ЧТО ЗДЕСЬ СТОРОЖИТСЯ
 *   1. `minorToRub` НЕ округляет: округление — решение показывающего, и
 *      принимать его в конверсии значит терять копейки до того, как вызывающий
 *      решил, нужны они ему.
 *   2. `rubToMinor` не отдаёт ноль и отрицательное (сервер отвергает цену
 *      меньше копейки), кроме явного `allowZero` — бесплатный ряд сеанса.
 *   3. Статический сторож: за пределами `lib/money.ts` нет ни одного `* 100`
 *      или `/ 100` рядом с деньгами. Он и есть защита от «поделил дважды»:
 *      второй раз можно поделить только литералом, а литерала больше нет.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { MINOR_PER_RUBLE, minorToRub, rubToMinor } from '@/lib/money'

describe('minorToRub', () => {
  it('делит ровно и не округляет', () => {
    expect(minorToRub(150_000)).toBe(1500)
    expect(minorToRub(155_050)).toBe(1550.5)
    expect(minorToRub(1)).toBe(0.01)
    expect(minorToRub(0)).toBe(0)
  })

  it('константа совпадает с делителем', () => {
    expect(minorToRub(MINOR_PER_RUBLE)).toBe(1)
  })
})

describe('rubToMinor', () => {
  it('переводит рубли в копейки', () => {
    expect(rubToMinor(850)).toBe(85_000)
    expect(rubToMinor('1250.50')).toBe(125_050)
  })

  it('понимает запятую как десятичный разделитель', () => {
    // Русская раскладка: `Number('1250,50')` — это NaN, и без замены поле
    // молча считалось бы невалидным.
    expect(rubToMinor('1250,50')).toBe(125_050)
  })

  it('округляет дробные копейки, а не обрезает их', () => {
    expect(rubToMinor(0.015)).toBe(2)
    expect(rubToMinor(1.004)).toBe(100)
  })

  it('никогда не отдаёт ноль или отрицательное значение', () => {
    // 0.001 ₽ = 0.1 копейки → округление дало бы 0, но нижняя граница 1 копейка.
    expect(rubToMinor(0.001)).toBe(1)
    expect(rubToMinor(0)).toBeNull()
    expect(rubToMinor(-5)).toBeNull()
  })

  it('ноль разрешает только по явному разрешению', () => {
    // Цена ряда сеанса может быть нулевой (бесплатный ряд), цена сетки зала —
    // нет. Разница в ПОЛИТИКЕ, а не в умножении, поэтому она параметр.
    expect(rubToMinor(0, { allowZero: true })).toBe(0)
    expect(rubToMinor('0', { allowZero: true })).toBe(0)
    expect(rubToMinor(-1, { allowZero: true })).toBeNull()
  })

  it('отклоняет нечисловой ввод', () => {
    expect(rubToMinor('')).toBeNull()
    expect(rubToMinor('abc')).toBeNull()
    expect(rubToMinor(null)).toBeNull()
    expect(rubToMinor(undefined)).toBeNull()
    expect(rubToMinor(Number.NaN)).toBeNull()
    expect(rubToMinor(Number.POSITIVE_INFINITY)).toBeNull()
  })
})

describe('туда и обратно', () => {
  it('копейки переживают пару конверсий без потерь', () => {
    for (const minor of [1, 99, 100, 101, 155_050, 150_000, 9_999_999]) {
      expect(rubToMinor(minorToRub(minor))).toBe(minor)
    }
  })
})

describe('один владелец конверсии', () => {
  /**
   * Файлы, где `* 100` / `/ 100` — это НЕ деньги, а доля.
   *
   * Список — ratchet с причиной, а не глушитель: добавление файла сюда означает
   * решение, что деление на 100 в нём не про единицы суммы.
   */
  const ALLOWED: Record<string, string> = {
    'lib/loyalty.ts':
      'процент от суммы (`subtotalMinor * SPEND_CAP_PERCENT / 100`) — это доля, а не перевод копеек в рубли',
  }

  /** Деньги рядом с умножением или делением на 100 — в любую сторону. */
  const MONEY_ARITH =
    /(?:[Mm]inor|[Rr]ub|[Pp]rice)[^\n;]{0,60}?[*/]\s*100\b|\b100\s*[*/][^\n;]{0,60}?(?:[Mm]inor|[Rr]ub|[Pp]rice)/

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

  it('за пределами lib/money.ts нет ни одного перевода единиц', () => {
    // `process.cwd()`, а не `import.meta.url`: под jsdom `fileURLToPath(new
    // URL(...))` падает с «The URL must be of scheme file». Vitest запускается
    // из корня проекта и в CI тоже.
    const root = join(process.cwd(), 'resources', 'js')
    const offenders: string[] = []

    for (const file of sourceFiles(root)) {
      const relative = file.slice(root.length + 1).replace(/\\/g, '/')

      if (relative === 'lib/money.ts' || relative in ALLOWED) {
        continue
      }

      const lines = codeOnly(readFileSync(file, 'utf8')).split('\n')

      lines.forEach((line, index) => {
        if (MONEY_ARITH.test(line)) {
          offenders.push(`${relative}:${index + 1}: ${line.trim()}`)
        }
      })
    }

    expect(offenders, offenders.join('\n')).toEqual([])
  })
})
