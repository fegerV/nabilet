/**
 * `lib/embed-bridge` — мост виджета → родительская страница.
 *
 * ЗДЕСЬ ПРОВЕРЯЕТСЯ ПОВЕДЕНИЕ, А НЕ СЕТЬ. Отправка идёт через `window.parent
 * `.postMessage`, который в jsdom мокается шпионом на самом окне: у iframe в
 * тестах нет кросс-доменного родителя, поэтому заменяем и `window.parent`, и
 * `window.top` одним объектом-«чужой рамкой». Формат не был бы проверен
 * интеграционно: события уходят в окно хозяина сайта, которого в юнит-среде нет.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  EMBED_SOURCE,
  installEmbedHeightReporter,
  isInsideIframe,
  parentOrigin,
  postEmbedEvent,
} from '@/lib/embed-bridge'

/** Подменить окно на «рамку»: у parent и top — другой объект. */
function enterIframe(parent: { postMessage: ReturnType<typeof vi.fn> } | Window): void {
  const fake = parent as unknown as Window
  vi.stubGlobal('parent', fake)
  vi.stubGlobal('top', fake)
}

/** Вернуть окно в «верхний уровень»: сам себе родитель. */
function leaveIframe(): void {
  vi.stubGlobal('parent', window)
  vi.stubGlobal('top', window)
}

describe('lib/embed-bridge: isInsideIframe', () => {
  beforeEach(() => {
    leaveIframe()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('вне рамки — false', () => {
    expect(isInsideIframe()).toBe(false)
  })

  it('внутри рамки — true', () => {
    enterIframe({ postMessage: vi.fn() })
    expect(isInsideIframe()).toBe(true)
  })
})

describe('lib/embed-bridge: postEmbedEvent', () => {
  beforeEach(() => {
    leaveIframe()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.referrer && Object.defineProperty(document, 'referrer', { value: '', configurable: true })
  })

  it('вне iframe ничего не отправляет', () => {
    // beforeEach вернул окно в «верхний уровень» (parent === window), поэтому
    // isInsideIframe() false и postEmbedEvent обязан выйти молча.
    const ownPost = vi.spyOn(window, 'postMessage')

    postEmbedEvent('cart', { count: 2 })

    expect(ownPost).not.toHaveBeenCalled()
    ownPost.mockRestore()
  })

  it('в iframe отправляет объект source+type+payload', () => {
    const post = vi.fn()
    enterIframe({ postMessage: post })

    postEmbedEvent('order', { order_id: '01H', total_minor: 1500 })

    expect(post).toHaveBeenCalledTimes(1)
    const [message, target] = post.mock.calls[0]
    expect(message).toMatchObject({
      source: EMBED_SOURCE,
      type: 'order',
      order_id: '01H',
      total_minor: 1500,
    })
    // jsdom-referrer пуст: мост не отказывается, а отправляет с '*' (см. шапку).
    expect(target).toBe('*')  })

  it('с известным referrer целится в его origin', () => {
    const post = vi.fn()
    enterIframe({ postMessage: post })
    Object.defineProperty(document, 'referrer', {
      value: 'https://partner.example/landing?utm=1',
      configurable: true,
    })

    postEmbedEvent('payment', { status: 'redirect' })

    const [message, target] = post.mock.calls[0]
    expect(message).toMatchObject({ source: EMBED_SOURCE, type: 'payment', status: 'redirect' })
    expect(target).toBe('https://partner.example')
  })

  it('не-HTTP referrer трактуется как неизвестный (отправка с *)', () => {
    const post = vi.fn()
    enterIframe({ postMessage: post })
    Object.defineProperty(document, 'referrer', { value: 'about:blank', configurable: true })

    postEmbedEvent('cart', { count: 0 })

    expect(post.mock.calls[0][1]).toBe('*')
  })
})

describe('lib/embed-bridge: parentOrigin', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    Object.defineProperty(document, 'referrer', { value: '', configurable: true })
  })

  it('возвращает только origin, без пути и query', () => {
    Object.defineProperty(document, 'referrer', {
      value: 'http://partner.example/landing?utm=1',
      configurable: true,
    })
    expect(parentOrigin()).toBe('http://partner.example')
  })

  it('пустой referrer — null', () => {
    expect(parentOrigin()).toBeNull()
  })
})

describe('lib/embed-bridge: installEmbedHeightReporter', () => {
  beforeEach(() => {
    leaveIframe()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('вне iframe не ставит наблюдателей и возвращает no-op', () => {
    const observe = vi.fn()
    vi.stubGlobal('ResizeObserver', class {
      observe = observe
      disconnect = vi.fn()
    })

    const stop = installEmbedHeightReporter()

    stop()
    expect(observe).not.toHaveBeenCalled()
  })

  it('в iframe ставит ResizeObserver и шлёт height сразу', () => {
    const observe = vi.fn()
    const disconnect = vi.fn()
    vi.stubGlobal('ResizeObserver', class {
      observe = observe
      disconnect = disconnect
    })
    const post = vi.fn()
    enterIframe({ postMessage: post })
    Object.defineProperty(document.documentElement, 'scrollHeight', {
      value: 640,
      configurable: true,
    })

    const stop = installEmbedHeightReporter()

    const [message, target] = post.mock.calls[0]
    expect(message).toMatchObject({ source: EMBED_SOURCE, type: 'height', height: 640 })
    expect(target).toBe('*')
    expect(observe).toHaveBeenCalled()

    // Отключение снимает наблюдателя — родитель больше не получает высоту.
    stop()
    expect(disconnect).toHaveBeenCalled()
  })
})
