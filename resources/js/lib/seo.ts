/**
 * Метаданные документа: `<title>`, `description` и Open Graph.
 *
 * Зачем отдельный модуль. Витрина — hash-SPA: сервер отдаёт один и тот же
 * `index.html` на все адреса, поэтому заголовок вкладки и превью ссылки у
 * всех страниц совпадали с корневыми. При этом `EventPage` ПОЛУЧАЛ
 * `seo_title`/`seo_description` из API и никак их не применял — поля
 * приезжали и выбрасывались (аудит витрины, пункт E3).
 *
 * Важная граница: для краулеров без JS (`EventPageController`) серверная
 * обёртка со своим og-блоком остаётся источником истины — она отдаёт реальный
 * HTML. Здесь мы приводим в порядок то, что видит браузер и JS-краулер, и НЕ
 * трогаем серверную разметку.
 *
 * Теги обновляются на месте (`upsertMeta`), а не добавляются вторым
 * экземпляром: два `<meta name="description">` — это лотерея, какой из них
 * возьмёт парсер (см. комментарий в `index.html` про порядок og-тегов).
 *
 * Каждый вызов `applySeo` ПОЛНОСТЬЮ определяет метаданные страницы: то, что
 * не передано, откатывается к значениям сайта из `index.html`. Иначе переход
 * «карточка события → оформление заказа» оставлял бы в `description` текст
 * мероприятия, а `og:image` — чужую афишу.
 */
import { watch, type WatchSource } from 'vue'

export interface SeoInput {
  /** Полный заголовок вкладки. Не передан — берётся заголовок сайта. */
  title?: string | null
  description?: string | null
  /** Картинка превью; относительный путь абсолютизируется. */
  image?: string | null
  url?: string | null
}

/** Значения из `index.html` — то, что видит пользователь на «нейтральных» страницах. */
const SITE = {
  title: 'NABILET — билеты на события',
  description: 'NABILET — билеты на события: выбор мест на схеме зала, оплата и QR-билеты.',
  ogDescription: 'Выбор мест на схеме зала, оплата онлайн, QR-билет на почту.',
} as const

/** Обрезать и превратить пустоту в `null`. */
function clean(value: string | null | undefined): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

/** Абсолютный URL картинки: `og:image` не понимает относительных путей. */
function absolute(url: string): string {
  try {
    return new URL(url, window.location.href).href
  } catch {
    return url
  }
}

/**
 * Найти существующий тег и переписать его, а не плодить дубликаты.
 *
 * `attr` — `name` для обычных метаданных и `property` для Open Graph: это
 * разные атрибуты, и тег, найденный по одному из них, вторым не находится.
 */
function upsertMeta(attr: 'name' | 'property', key: string, content: string): void {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

/** Убрать тег: значение по умолчанию для него — «отсутствует». */
function removeMeta(attr: 'name' | 'property', key: string): void {
  document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)?.remove()
}

/** Применить метаданные к текущему документу. */
export function applySeo(seo: SeoInput = {}): void {
  const title = clean(seo.title) ?? SITE.title
  document.title = title
  upsertMeta('property', 'og:title', title)

  const description = clean(seo.description)
  upsertMeta('name', 'description', description ?? SITE.description)
  upsertMeta('property', 'og:description', description ?? SITE.ogDescription)

  const image = clean(seo.image)
  if (image) upsertMeta('property', 'og:image', absolute(image))
  else removeMeta('property', 'og:image')

  const url = clean(seo.url)
  if (url) upsertMeta('property', 'og:url', absolute(url))
  else removeMeta('property', 'og:url')
}

/**
 * Реактивно применять метаданные страницы.
 *
 * Геттер возвращает `null`, пока данных нет (событие ещё грузится или запрос
 * упал) — тогда документ не трогаем и оставляем заголовок предыдущего экрана,
 * а не мигаем дефолтным.
 */
export function useSeo(source: WatchSource<SeoInput | null>): void {
  watch(
    source,
    (seo) => {
      if (seo) applySeo(seo)
    },
    { immediate: true },
  )
}
