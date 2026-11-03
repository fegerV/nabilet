/**
 * Генератор embed-кода (ТЗ §48–51): `iframe` со витриной + слушатель моста.
 *
 * ПОЧЕМУ ЧИСТЫЕ ФУНКЦИИ, А НЕ КОМПОНЕНТ. Сниппеты вставляются партнёру
 * копипастой, и их текст — контракт: любое «случайное» переформатирование
 * Vue-шаблоном (перенос, кавычки) меняет то, что окажется в коде заказчика.
 * Строки собирают детерминированные функции, а `AdminEmbedPage` только
 * показывает результат и кнопку «Копировать»; Vitest фиксирует вывод буква
 * в букву (tests/Unit/Admin/embedCode.test.ts).
 *
 * КАК ЖИВЁТ РАМКА. `src` — hash-адрес витрины (`/#/event/{slug}`): SPA
 * работает на hash-истории, и красивые URL в чужом iframe невозможны без
 * переписывания на сервере (см. `router/index.ts`). `embed_token` кладётся
 * в hash-часть: его читает `route.query` vue-router'а. Денежный флоу рамки
 * пока НЕ переключён на `/api/v1/embed/*` (это следующий шаг) — переключение
 * не потребует правки этого генератора: токен уже в адресе.
 */

/** id элемента `<iframe>` в сниппете; слушатель находит по нему рамку. */
export const EMBED_FRAME_ID = 'nabilet-embed'

/** Событие моста, которое слушает родительская страница. */
export const WIDGET_MESSAGE_SOURCE = 'nabilet-embed'

export interface EmbedCodeOptions {
  /** Адрес витрины NABILET (обычно `window.location.origin` админки). */
  storefrontOrigin: string
  /** Путь внутри SPA без `#`: `/`, `/event/my-event`. */
  path?: string
  /** Embed-токен из `POST /admin/embed/keys` (показывается один раз). */
  token: string
  /** Подпись рамки — уйдёт в атрибуты `title`/`aria-label`. */
  title?: string
}

/**
 * Нормализованный путь витрины для `src`. Пустое — каталог `/` (форма не
 * требует заполнения), ведущее `/` добавляем, хвостовые `/` и `#` снимаем:
 * сниппет должен выглядеть одинаково, что бы ни ввёл администратор.
 */
export function normalizeWidgetPath(path: string | undefined): string {
  const trimmed = (path ?? '').trim().replace(/^#/, '')
  const withLeading = trimmed.startsWith('/') ? trimmed : `/${trimmed}`
  return withLeading.replace(/\/+$/, '') || '/'
}

/**
 * Адрес рамки: `https://nabilet.example/#/event/x?embed_token=…`.
 * Токен кодируется `encodeURIComponent`: штатные токены спецсимволов не
 * содержат, но значение может быть введено вручную — `&` или `#` в нём
 * разорвали бы URL так, что рамка молча потеряла бы токен.
 */
export function buildEmbedUrl(options: EmbedCodeOptions): string {
  const origin = options.storefrontOrigin.replace(/\/+$/, '')
  const path = normalizeWidgetPath(options.path)
  const token = encodeURIComponent(options.token.trim())
  return `${origin}/#${path}?embed_token=${token}`
}

/**
 * `<iframe>`-сниппет. Ширина 100% (рамка растягивается по колонке родителя),
 * стартовая высота 640 — слушатель моста (`height`) подгоняет сразу после
 * первого рендера, значение нужно только до прихода первого сообщения.
 */
export function buildIframeSnippet(options: EmbedCodeOptions): string {
  const url = buildEmbedUrl(options)
  const title = (options.title ?? 'NABILET').replace(/"/g, '&quot;')

  return [
    `<iframe id="${EMBED_FRAME_ID}" src="${url}" title="${title}"`,
    `  style="width:100%;height:640px;border:0" loading="lazy"></iframe>`,
  ].join('\n')
}

/**
 * Слушатель моста на родительской странице.
 *
 * Эффекты опциональны для партнёра: автоподгонка высоты рамки (`height`),
 * оповещение о созданном заказе (`order`) и «оплата прошла»
 * (`payment.completed`); ветки `cart` и `payment.redirect` — точки,
 * где хозяину сайта идея показывать свои индикаторы. Фильтр `source`
 * обязательный: в том же окне живут и чужие postMessage (аналитика,
 * расширения) — реагировать на мусор значило бы портить себе вёрстку.
 */
export function buildListenerSnippet(): string {
  return [
    '<script>',
    `  window.addEventListener('message', function (e) {`,
    `    var m = e.data;`,
    `    if (!m || m.source !== '${WIDGET_MESSAGE_SOURCE}') return;`,
    `    var frame = document.getElementById('${EMBED_FRAME_ID}');`,
    `    if (m.type === 'height' && frame && m.height) {`,
    `      frame.style.height = m.height + 'px';`,
    `    }`,
    `    if (m.type === 'cart') {`,
    `      // m.count — билетов выбрано; покажите свой индикатор`,
    `    }`,
    `    if (m.type === 'order') {`,
    `      // m.order_id, m.total_minor — заказ создан`,
    `    }`,
    `    if (m.type === 'payment' && m.status === 'completed') {`,
    `      // оплата прошла внутри рамки: можно прятать виджет`,
    `    }`,
    `    if (m.type === 'payment' && m.status === 'redirect') {`,
    `      // покупатель ушёл на страницу провайдера: рамка сейчас опустеет`,
    `    }`,
    `  });`,
    '</script>',
  ].join('\n')
}

/** Полный блок для копирования: рамка и слушатель рядом. */
export function buildFullSnippet(options: EmbedCodeOptions): string {
  return `${buildIframeSnippet(options)}\n${buildListenerSnippet()}`
}
