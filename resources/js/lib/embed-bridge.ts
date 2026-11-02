/**
 * Мост виджета → родительская страница (postMessage).
 *
 * ЗАЧЕМ. Embed-виджет живёт в чужом <iframe> (бэкенд `/api/v1/embed/*` котируется
 * по паре «embed_token + Origin»), но контракт ТЗ §48–51 не ограничивается
 * отрисовкой: родительская страница должна УЗНАВАТЬ, что произошло внутри
 * рамки — изменилась корзина, создан заказ, началась оплата. Без этого
 * хозяин сайта не может ни показать свою кнопку «в корзине N билетов», ни
 * снять виджет после успешной оплаты, ни подогнать высоту рамки.
 *
 * КАНАЛ ОДНОСТОРОННИЙ. Виджет — говорящий, родитель — слушающий. Слушать
 * сообщения родителя виджет не обязан: все решения о контенте (событие,
 * сеанс, тема) принимаются строкой URL, а не договариваются в рантайме.
 * Это сознательное сужение: входящий postMessage — второй атакующий
 * интерфейс внутри рамки, и его нет, пока нет потребности.
 *
 * ФОРМАТ. `{ source: 'nabilet-embed', type: …, …payload }`. Поле `source`
 * разделяет наши события от мусора чужих скриптов той же рамки (аналитика,
 * соцвиджеты) — подписчику достаточно одного if. Типы: `cart`, `order`,
 * `payment`, `height`.
 *
 * ЦЕЛЕВОЙ ORIGIN. Из `document.referrer`: внутри iframe это адрес страницы,
 * открывшей рамку. Отправка родителю — НЕ секретный канал (в событиях нет
 * персональных данных: счётчики, суммы в минорных единицах, id заказа),
 * поэтому пустой/парс-неразличимый referrer (строгий `Referrer-Policy`
 * хозяина сайта) — не отказ от моста, а отправка с `'*'`: losми события
 * видит лишь та страница, которая открыла рамку.
 *
 * ВНЕ IFRAME — НОЛЬ УЧЕБНЫХ ЭФФЕКТОВ. Витрина и так проверяет, что
 * `window.parent !== window`, прежде чем что-то отправлять: обычный браузер
 * посетителя не получает ни одного postMessage, даже когда код исполнен.
 */
export const EMBED_SOURCE = 'nabilet-embed'

export type EmbedEventType = 'cart' | 'order' | 'payment' | 'height'

export interface EmbedEvent {
  source: typeof EMBED_SOURCE
  type: EmbedEventType
}

/** true, когда SPA исполняется внутри `<iframe>` (embed-режим). */
export function isInsideIframe(): boolean {
  try {
    return window.parent !== window
  } catch {
    // Кросс-доменный доступ к `window.parent` может бросить — значит, рамка
    // есть и чужая.
    return true
  }
}

/**
 * Origin страницы-хозяина из `document.referrer`, либо `null`.
 *
 * Пустой referrer внутри iframe — легальный случай (`Referrer-Policy:
 * no-referrer`, приватный режим): тогда мост отправляет с `'*'`, см. шапку.
 */
export function parentOrigin(): string | null {
  if (typeof document === 'undefined') return null

  const raw = document.referrer
  if (!raw) return null

  try {
    const url = new URL(raw)
    return (url.protocol === 'https:' || url.protocol === 'http:') ? url.origin : null
  } catch {
    return null
  }
}

/**
 * Отправить событие родителю. Молча не делает ничего вне iframe: вызовы
 * расставлены в общем коде покупки, который исполняется и на обычной витрине.
 */
export function postEmbedEvent(
  type: EmbedEventType,
  payload: Record<string, unknown> = {},
): void {
  if (!isInsideIframe()) return

  const message: EmbedEvent = { source: EMBED_SOURCE, type, ...payload }
  const target = parentOrigin()

  // `target === null` → referrer недоступен: события не секретны, а адресат
  // и так один — окно, открывшее рамку.
  window.parent.postMessage(message, target ?? '*')
}

/**
 * Держать родителя в курсе высоты рамки.
 *
 * `iframe` по умолчанию фиксированной высоты, а контент виджета (схема зала,
 * шаги оформления) растёт вниз. Родитель слушает `height` и ставит
 * `iframe.style.height` — так виджет вписывается в чужую страницу без
 * прокрутки внутри рамки. Возвращает функцию отключения (для `onUnmounted`).
 */
export function installEmbedHeightReporter(): () => void {
  if (!isInsideIframe()) return () => {}

  let last = 0
  const report = (): void => {
    const height = Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0)
    // Порог в 1px гасит серию комментариев о микросдвигах скроллбара.
    if (Math.abs(height - last) < 1) return
    last = height
    postEmbedEvent('height', { height })
  }

  report()

  const observer = new ResizeObserver(report)
  observer.observe(document.documentElement)
  if (document.body) observer.observe(document.body)

  return () => observer.disconnect()
}
