/**
 * Библиотека файлов — общие типы и правила.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ МОДУЛЬ, А НЕ ЧАСТЬ СТРАНИЦЫ
 *
 * Здесь лежит всё, что можно проверить без браузера: классификация файла по
 * типу, читаемый размер, проверка перед загрузкой и подписи «где используется».
 * Ошибка в любом из этих правил не даёт ни исключения, ни красного экрана:
 * картинка показывается значком видео, «10.0 МБ» вместо «10 МБ» никто не
 * заметит, а связь с мероприятием пропадает из подписи. Поэтому правила живут
 * вне компонента и проверяются `vitest`-ом напрямую.
 *
 * `humanSize` живёт ЗДЕСЬ, хотя им пользуется и галерея мероприятия
 * (`lib/eventGallery.ts`): функция общая, и вторая её копия разошлась бы с
 * первой ровно так же, как когда-то разошлись три копии сборки URL ассетов.
 */

/** Связь файла с объектом — элемент поля `links` в ответе `MediaAssetResource`. */
export interface MediaLinkRef {
  id: number
  entity_type: string
  entity_id: number
  role: string
  position: number
}

/** Файл в библиотеке — ответ `MediaAssetResource`. */
export interface MediaAsset {
  id: number
  public_id: string
  disk: string
  path: string
  url: string
  filename: string
  mime_type: string
  size_bytes: number
  width: number | null
  height: number | null
  checksum: string | null
  title: string | null
  alt_text: string | null
  variants_json: unknown
  created_at: string | null
  updated_at: string | null
  /**
   * `undefined` — связи не запрашивались (`?`), `[]` — запрашивались и их нет.
   * Разница существенна: «нигде не используется» и «неизвестно» — не одно и то
   * же, и показывать первое вместо второго нельзя.
   */
  links?: MediaLinkRef[]
}

/** Тип файла так, как его видит интерфейс. */
export type MediaKind = 'image' | 'video' | 'document' | 'other'

/**
 * Типы, которые принимает сервер (`MediaController::ALLOWED_MIMES`).
 *
 * Список продублирован намеренно: сервер обязан проверять сам, а браузер не
 * должен гонять по сети десятимегабайтный файл, чтобы получить 422. Разъехавшись,
 * эти два списка дают ровно тот дефект, который здесь уже случался: интерфейс
 * предлагал видео, а сервер отвечал «должен быть одного из типов: jpg, jpeg…».
 */
export const MEDIA_TYPES: readonly string[] = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'image/avif',
  'image/gif',
  'image/svg+xml',
  'application/pdf',
  'video/mp4',
  'video/webm',
]

/** Значение атрибута `accept` для поля выбора файла. */
export const MEDIA_ACCEPT: string = MEDIA_TYPES.join(',')

/** Человекочитаемый перечень типов для подсказки и сообщения об ошибке. */
export const MEDIA_TYPES_LABEL = 'jpg, png, webp, avif, gif, svg, pdf, mp4, webm'

/** Совпадает с `MAX_SIZE_KB` в `MediaController` (10240 КБ). */
export const MEDIA_MAX_BYTES = 10 * 1024 * 1024

/** Читаемый размер файла. */
export function humanSize(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 Б'
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} КБ`
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}

/**
 * К какому виду относится файл.
 *
 * `application/mp4` проверяется отдельно: это зарегистрированный тип того же
 * контейнера, и его отдаёт определение типа по СОДЕРЖИМОМУ (Symfony), когда у
 * файла нет расширения. Файл, зарегистрированный через API по `path`, вообще
 * может прийти с любым `mime_type` — поэтому проверяем и это значение, иначе
 * видео показалось бы «другим файлом» с иконкой документа.
 */
export function kindOf(mimeType: string | null | undefined): MediaKind {
  const mime = (mimeType ?? '').toLowerCase()

  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('video/') || mime === 'application/mp4') return 'video'
  if (mime === 'application/pdf') return 'document'

  return 'other'
}

/**
 * Проверка файла ДО отправки.
 *
 * Возвращает текст ошибки или `null`. Порядок проверок — как в галерее
 * мероприятия (`lib/eventGallery.ts`): сначала тип, затем размер, затем пустой
 * файл. Один порядок на два экрана означает, что сообщение об одном и том же
 * файле не будет отличаться от раздела к разделу.
 */
export function validateMediaFile(file: File): string | null {
  if (!MEDIA_TYPES.includes(file.type)) {
    return `«${file.name}»: поддерживаются ${MEDIA_TYPES_LABEL}.`
  }

  if (file.size > MEDIA_MAX_BYTES) {
    return `«${file.name}»: ${humanSize(file.size)} — больше предела ${humanSize(MEDIA_MAX_BYTES)}.`
  }

  if (file.size === 0) {
    return `«${file.name}»: файл пустой.`
  }

  return null
}

const ENTITY_LABELS: Record<string, string> = {
  event: 'мероприятие',
  venue: 'площадка',
  hall: 'зал',
  organization: 'организация',
  page: 'страница',
}

const ROLE_LABELS: Record<string, string> = {
  gallery: 'галерея',
  poster: 'афиша',
  cover: 'обложка',
  logo: 'логотип',
}

/**
 * Где используется файл — по одной подписи на связь.
 *
 * Незнакомый тип или роль показываются КАК ЕСТЬ, а не прячутся: список типов
 * открыт (колонка `entity_type` — свободная строка), и молча выбросить связь
 * значило бы написать «файл нигде не используется» там, где он используется.
 */
export function usageLabels(links: MediaLinkRef[] | undefined): string[] {
  if (!links || links.length === 0) return []

  return links.map((link) => {
    const entity = ENTITY_LABELS[link.entity_type] ?? link.entity_type
    const role = ROLE_LABELS[link.role] ?? link.role

    return `${entity} #${link.entity_id} · ${role}`
  })
}

/** «1200×800» или пустая строка, если размеров нет (PDF, видео, SVG). */
export function dimensionsLabel(asset: Pick<MediaAsset, 'width' | 'height'>): string {
  if (asset.width === null || asset.height === null) return ''

  return `${asset.width}×${asset.height}`
}

/** Дата загрузки в виде «9 октября 2026». Пустая строка, если даты нет. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return ''

  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''

  return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

/**
 * Страница, приведённая к допустимому диапазону.
 *
 * После удаления последнего файла на последней странице текущая страница
 * становится пустой: сервер вернул бы пустой список, и это выглядело бы как
 * «файлы пропали». Поэтому номер прижимается к последней существующей странице.
 */
export function clampPage(page: number, lastPage: number): number {
  if (!Number.isFinite(page) || page < 1) return 1
  if (!Number.isFinite(lastPage) || lastPage < 1) return 1

  return Math.min(Math.floor(page), Math.floor(lastPage))
}
