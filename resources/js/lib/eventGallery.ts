/**
 * Галерея мероприятия — типы и чистые правила.
 *
 * ПОЧЕМУ ЛОГИКА ЗДЕСЬ, А НЕ В КОМПОНЕНТЕ
 *
 * Всё, что можно проверить без браузера, лежит в этом файле: проверка файла
 * перед загрузкой и расчёт новых позиций при перестановке. В компоненте
 * остаётся только разговор с API и разметка. Так правила проверяются
 * `vitest`-ом напрямую, без монтирования Vue и без мока сети — а правило
 * «какие позиции отправить» ошибается тихо: галерея просто перестаёт
 * переставляться, и по виду кода этого не видно.
 *
 * СЕРВЕР — ИСТОЧНИК ПРАВДЫ О ПОРЯДКЕ
 *
 * Позиция приходит с сервера (`media_links.position`) и отправляется обратно
 * (`PATCH /media/{id}` с `position`). Локальная перестановка массива — только
 * для мгновенной отзывчивости; после ответа список перечитывается. Иначе два
 * администратора, открывшие одну галерею, разошлись бы в порядке навсегда.
 */
import { humanSize } from './mediaLibrary'

/** Файл внутри связи — то, что отдаёт `MediaLinkResource`. */
export interface GalleryMedia {
  id: number
  public_id: string
  url: string
  filename: string
  mime_type: string
  size_bytes: number
  width: number | null
  height: number | null
  title: string | null
  alt_text: string | null
}

/** Элемент галереи: связь файла с мероприятием. */
export interface GalleryItem {
  id: number
  media_id: number
  entity_type: string
  entity_id: number
  role: string
  position: number
  /** `null` — связь пережила файл; такие строки сервер не отдаёт, но тип обязан это допускать. */
  media: GalleryMedia | null
}

/** Совпадает с `MAX_SIZE_KB` в `MediaController`. */
export const GALLERY_MAX_BYTES = 10 * 1024 * 1024

/**
 * Совпадает с `ALLOWED_MIMES` в `MediaController` (без pdf и svg: в галерее
 * мероприятия им не место — это фотографии и видео, а не документы).
 */
export const GALLERY_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'video/mp4', 'video/webm']

/**
 * Проверка файла ДО отправки.
 *
 * Возвращает текст ошибки или `null`. Дублирует серверную проверку намеренно:
 * сервер обязан проверять сам (клиенту доверять нельзя), но 10-мегабайтный файл
 * с неверным типом незачем гонять по сети, чтобы получить 422.
 */
export function validateGalleryFile(file: File): string | null {
  if (!GALLERY_TYPES.includes(file.type)) {
    return `«${file.name}»: поддерживаются jpg, png, webp, avif, gif, mp4 и webm.`
  }

  if (file.size > GALLERY_MAX_BYTES) {
    return `«${file.name}»: ${humanSize(file.size)} — больше предела ${humanSize(GALLERY_MAX_BYTES)}.`
  }

  if (file.size === 0) {
    return `«${file.name}»: файл пустой.`
  }

  return null
}

/** Изображение ли это — от этого зависит, показывать превью или иконку. */
export function isImage(item: GalleryItem): boolean {
  return (item.media?.mime_type ?? '').startsWith('image/')
}

/**
 * Новые позиции после перемещения элемента на одну ступень.
 *
 * Возвращает РОВНО ДВЕ записи — для перемещаемого элемента и для того, с кем
 * он меняется местами, — потому что менять позиции всех элементов списка
 * значит отправлять N запросов там, где достаточно двух. На границе списка
 * возвращает пустой массив: «выше первого» не существует, и это не ошибка,
 * а отсутствие действия.
 *
 * @param direction -1 — выше (меньше `position`), 1 — ниже.
 */
export function reorderPlan(
  items: GalleryItem[],
  index: number,
  direction: -1 | 1,
): Array<{ mediaId: number; position: number }> {
  const target = index + direction

  if (index < 0 || index >= items.length || target < 0 || target >= items.length) {
    return []
  }

  const moved = items[index]
  const swapped = items[target]

  return [
    { mediaId: moved.media_id, position: swapped.position },
    { mediaId: swapped.media_id, position: moved.position },
  ]
}

/**
 * Перестановка в локальном массиве — для мгновенного отклика.
 *
 * Возвращает НОВЫЙ массив: мутировать `ref`-значение на месте в Vue значит
 * менять то, что уже отрисовано, и полагаться на то, что реактивность заметит
 * изменение внутри элемента. Новый массив делает изменение явным.
 */
export function moveWithin<T>(items: T[], index: number, direction: -1 | 1): T[] {
  const target = index + direction

  if (index < 0 || index >= items.length || target < 0 || target >= items.length) {
    return items
  }

  const next = items.slice()
  const [moved] = next.splice(index, 1)
  next.splice(target, 0, moved)

  return next
}
