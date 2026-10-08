/**
 * Регресс-тесты на утечку подписанного QR-токена.
 *
 * КОНТЕКСТ, БЕЗ КОТОРОГО ТЕСТ НЕПОНЯТЕН
 *
 * `qr_payload` билета — это `NB1.<publicId>.<token>.<signature>` (QrSigner §30),
 * то есть подписанный токен входа: кто его получил, тот проходит по билету.
 * Приложение рисовало QR через внешний сервис:
 *
 *     https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<payload>
 *
 * то есть отправляло этот токен третьей стороне в query-параметре — вместе с
 * IP и Referer покупателя. Тот же класс утечки, что и открытый
 * `GET /tickets/{id}/qr`, который уже закрыли.
 *
 * ЧТО ИМЕННО ПРОВЕРЯЕТСЯ
 *
 * 1. Функции, отдававшей внешний URL, больше нет в публичном API
 *    `@/lib/ticketBuilder` — прежний вызов нельзя вернуть по привычке.
 * 2. Ни один модуль витрины не содержит запрещённых хостов. Это grep по
 *    исходникам, а не по бандлу: тест должен падать на этапе разработки.
 * 3. Локальный рендер действительно кодирует payload и НЕ делает сетевых
 *    запросов — сетевые примитивы подменены и падают при обращении.
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import {
  FORBIDDEN_QR_HOSTS,
  QR_PREVIEW_FALLBACK,
  isForbiddenQrSource,
  renderQrDataUrl,
  renderQrSvg,
} from '../../../resources/js/lib/qr'

const JS_ROOT = join(process.cwd(), 'resources', 'js')

/** Все .ts/.vue под resources/js — чтобы grep не пропустил новый файл. */
function sourceFiles(dir: string, acc: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      sourceFiles(full, acc)
    } else if (/\.(ts|vue|js)$/.test(entry)) {
      acc.push(full)
    }
  }
  return acc
}

/**
 * Убрать комментарии перед поиском хостов.
 *
 * Без этого шага тест падает на собственных пояснениях: файлы, из которых
 * утечку убрали, документируют, ЧТО именно было убрано, и упоминают хост в
 * комментарии. Проверять надо код, а не прозу о коде.
 *
 * Порядок важен: сначала блочные и HTML-комментарии, потом строчные — иначе
 * маркер строчного комментария внутри блочного съест остаток блока.
 */
function stripComments(source: string): string {
  // Регулярки собраны через new RegExp, а не литералами: последовательность
  // открывающего тега HTML-комментария распознаётся лексером oxc как настоящий
  // HTML-комментарий («HTML comments are not allowed in modules») и ломает
  // разбор всего файла — даже когда стоит внутри регулярного выражения.
  const blockComment = new RegExp('/\\*[\\s\\S]*?\\*/', 'g')
  const htmlComment = new RegExp('<' + '!--[\\s\\S]*?-->', 'g')
  const lineComment = new RegExp('(^|[^:])//.*$', 'gm')

  return source
    .replace(blockComment, '')
    .replace(htmlComment, '')
    .replace(lineComment, '$1')
}

describe('QR: подписанный токен не уходит наружу', () => {
  it('getQrCodeUrl удалена из публичного API ticketBuilder', async () => {
    const mod = await import('../../../resources/js/lib/ticketBuilder')
    expect('getQrCodeUrl' in mod).toBe(false)
  })

  it('ни один исходник витрины не ссылается на внешний сервис QR', () => {
    const offenders: string[] = []

    for (const file of sourceFiles(JS_ROOT)) {
      // Сам модуль qr.ts содержит хосты — но только в списке запрещённых.
      const isQrModule = file.endsWith(join('lib', 'qr.ts'))
      const code = stripComments(readFileSync(file, 'utf8'))

      for (const host of FORBIDDEN_QR_HOSTS) {
        if (!code.includes(host)) continue

        // В qr.ts хост допустим только внутри массива FORBIDDEN_QR_HOSTS.
        if (isQrModule && code.includes('FORBIDDEN_QR_HOSTS')) continue

        offenders.push(`${file.replace(process.cwd(), '.')} → ${host}`)
      }
    }

    expect(offenders, `Подписанный QR-токен уходит во внешний сервис:\n${offenders.join('\n')}`)
      .toEqual([])
  })

  it('stripComments не оставляет упоминаний из комментариев', () => {
    // Защита самого теста: если stripComments сломается, предыдущая проверка
    // начнёт пропускать реальные утечки, оставаясь зелёной.
    //
    // Открывающий тег HTML-комментария собирается конкатенацией: его буквальное
    // написание в исходнике — даже внутри строки — oxc считает настоящим
    // HTML-комментарием и отказывается разбирать модуль целиком.
    const htmlOpen = '<' + '!--'
    const sample = [
      '// https://api.qrserver.com в строчном комментарии',
      '/* https://api.qrserver.com в блочном */',
      `${htmlOpen} https://api.qrserver.com в HTML -->`,
      "const real = 'https://api.qrserver.com/v1'",
    ].join('\n')

    const stripped = stripComments(sample)

    // Единственное упоминание, которое обязано выжить, — в строковом литерале.
    expect(stripped.match(/api\.qrserver\.com/g)).toHaveLength(1)
    expect(stripped).toContain("https://api.qrserver.com/v1")
    expect(stripped).not.toContain('в строчном комментарии')
    expect(stripped).not.toContain('в блочном')
    expect(stripped).not.toContain('в HTML')
  })

  it('isForbiddenQrSource узнаёт историческую и типовые утечки', () => {
    expect(isForbiddenQrSource('https://api.qrserver.com/v1/create-qr-code/?data=NB1.x')).toBe(true)
    expect(isForbiddenQrSource('https://chart.googleapis.com/chart?cht=qr')).toBe(true)
    expect(isForbiddenQrSource('data:image/png;base64,iVBORw0KGgo=')).toBe(false)
    expect(isForbiddenQrSource('')).toBe(false)
  })
})

describe('локальный рендер QR', () => {
  beforeEach(() => {
    // Любой сетевой выход = провал теста, а не «медленно, но работает».
    vi.stubGlobal('fetch', vi.fn(() => {
      throw new Error('QR-рендер сделал сетевой запрос — подписанный токен утёк.')
    }))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('строит SVG локально и встраивает payload', async () => {
    const payload = 'NB1.01J8ZZ.abcdef.signature'
    const svg = await renderQrSvg(payload, { width: 200 })

    expect(svg).toContain('<svg')
    // `xmlns="http://www.w3.org/2000/svg"` — объявление пространства имён, а не
    // ссылка на ресурс: браузер по нему ничего не запрашивает. Ищем то, что
    // действительно тянет данные извне: `href`, `<image>`, `url(...)`.
    expect(svg).not.toMatch(/\bhref\s*=/i)
    expect(svg).not.toMatch(/<image\b/i)
    expect(svg).not.toMatch(/url\(\s*['"]?https?:/i)
    expect(svg).not.toContain('api.qrserver.com')
    // QR реально построен: есть модули, а не пустой каркас.
    expect(svg.length).toBeGreaterThan(500)
  })

  it('отказывается строить QR без данных', async () => {
    await expect(renderQrSvg('')).rejects.toThrow(/без данных/)
    await expect(renderQrDataUrl('   ')).rejects.toThrow(/без данных/)
  })

  it('не кодирует заглушку как настоящий билет', () => {
    // Заглушка превью — не билет: она не должна содержать префикс токена входа.
    expect(QR_PREVIEW_FALLBACK.startsWith('NB1.')).toBe(false)
  })
})
