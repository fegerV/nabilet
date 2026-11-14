/**
 * `lib/embed-code` — генератор embed-кода для админки.
 *
 * ЗДЕСЬ ТЕКСТ — КОНТРАКТ. Сниппет уходит партнёру копипастой: «незначительное»
 * изменение пробела, кавычек или порядка веток слушателя ломает код на чужом
 * сайте, который никто не перезаливает. Поэтому тест фиксирует вывод буква
 * в букву, а не «содержит подстроку».
 */
import { describe, expect, it } from 'vitest'
import {
  EMBED_FRAME_ID,
  buildEmbedUrl,
  buildFullSnippet,
  buildIframeSnippet,
  buildListenerSnippet,
  normalizeWidgetPath,
} from '@/lib/embed-code'

describe('lib/embed-code: normalizeWidgetPath', () => {
  it('пусто — каталог', () => {
    expect(normalizeWidgetPath(undefined)).toBe('/')
    expect(normalizeWidgetPath('')).toBe('/')
    expect(normalizeWidgetPath('   ')).toBe('/')
  })

  it('хвостовые слэши и решётка снимаются', () => {
    expect(normalizeWidgetPath('/event/x/')).toBe('/event/x')
    expect(normalizeWidgetPath('#/event/x')).toBe('/event/x')
    expect(normalizeWidgetPath('///')).toBe('/')
  })

  it('ведущий слэш добавляется', () => {
    expect(normalizeWidgetPath('event/x')).toBe('/event/x')
  })
})

describe('lib/embed-code: buildEmbedUrl', () => {
  it('собирает настоящий адрес витрины с токеном в query', () => {
    expect(
      buildEmbedUrl({ storefrontOrigin: 'https://tickets.example', token: 'TKN1' }),
    ).toBe('https://tickets.example/?embed_token=TKN1')
    expect(
      buildEmbedUrl({ storefrontOrigin: 'https://tickets.example/', path: '/event/x/', token: 'TKN1' }),
    ).toBe('https://tickets.example/event/x?embed_token=TKN1')
  })

  it('ручной ввод токена с & и # не разрывает URL', () => {
    const url = buildEmbedUrl({ storefrontOrigin: 'https://tickets.example', token: 'a&b#c' })
    expect(url).toBe('https://tickets.example/?embed_token=a%26b%23c')
  })

  it('обрезает пробелы токена', () => {
    expect(
      buildEmbedUrl({ storefrontOrigin: 'https://tickets.example', token: '  TK ' }),
    ).toBe('https://tickets.example/?embed_token=TK')
  })
})

describe('lib/embed-code: buildIframeSnippet', () => {
  it('точный текст: id, стартовая высота 640, lazy', () => {
    expect(
      buildIframeSnippet({ storefrontOrigin: 'https://tickets.example', path: '/event/x', token: 'TK' }),
    ).toBe(
      `<iframe id="nabilet-embed" src="https://tickets.example/event/x?embed_token=TK" title="NABILET"\n` +
        `  style="width:100%;height:640px;border:0" loading="lazy"></iframe>`,
    )
  })

  it('кавычки в подписи экранируются — атрибут не разрывается', () => {
    const snippet = buildIframeSnippet({
      storefrontOrigin: 'https://tickets.example',
      token: 'TK',
      title: 'Билеты "Ромэо"',
    })
    expect(snippet).toContain('title="Билеты &quot;Ромэо&quot;"')
  })

  it('id рамки совпадает с константой (на него смотрит слушатель)', () => {
    expect(EMBED_FRAME_ID).toBe('nabilet-embed')
 expect(buildIframeSnippet({ storefrontOrigin: 'https://t.example', token: 'TK' })).toContain(
      `id="nabilet-embed"`,
    )
  })
})

describe('lib/embed-code: buildListenerSnippet', () => {
  const listener = buildListenerSnippet()

  it('фильтрует по source и находит рамку по id', () => {
    expect(listener).toContain(`m.source !== 'nabilet-embed'`)
    expect(listener).toContain(`document.getElementById('nabilet-embed')`)
  })

  it('содержит все пять веток моста в видимом для партнёра виде', () => {
    expect(listener).toContain(`m.type === 'height'`)
    expect(listener).toContain(`frame.style.height = m.height + 'px';`)
    expect(listener).toContain(`m.type === 'cart'`)
    expect(listener).toContain(`m.type === 'order'`)
    expect(listener).toContain(`m.type === 'payment' && m.status === 'completed'`)
    expect(listener).toContain(`m.type === 'payment' && m.status === 'redirect'`)
  })

  it('закрывающий тег закрывает блок ровно один раз — в конце', () => {
    expect(listener.startsWith('<script>')).toBe(true)
    expect(listener.endsWith('</script>')).toBe(true)
    // Литеральный `</script` внутри тела слушателя (в строковом литерале
    // нашего сгенерированного кода) закрыл бы скрипт партнёра раньше
    // времени — поэтому тег допустим только терминатором блока.
    const body = listener.slice('<script>'.length, -'</script>'.length)
    expect(body).not.toContain('</script>')
  })
})

describe('lib/embed-code: buildFullSnippet', () => {
  it('рамка и слушатель рядом, вывод детерминирован', () => {
    const options = { storefrontOrigin: 'https://tickets.example', token: 'TK' }
    const full = buildFullSnippet(options)
    expect(full).toBe(`${buildIframeSnippet(options)}\n${buildListenerSnippet()}`)
    expect(buildFullSnippet(options)).toBe(full)
  })
})
