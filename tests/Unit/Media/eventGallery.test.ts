/**
 * Галерея мероприятия: правила, которые ломаются незаметно.
 *
 * Повод — «Дополнительные фото и видео: отсутствует полностью». Раздел появился,
 * и всё, что в нём можно сломать без единой ошибки в консоли, собрано в
 * `@/lib/eventGallery`: проверку файла перед отправкой и расчёт новых позиций
 * при перестановке.
 *
 * ПОЧЕМУ ЭТО СТОИТ ТЕСТОВ
 *
 * Ошибка в `reorderPlan()` не даёт ни исключения, ни красного экрана: галерея
 * просто перестаёт переставляться, а на границе списка отправляется лишний
 * запрос, который меняет порядок на противоположный. Ошибка в проверке файла
 * отправляет на сервер то, что он всё равно отклонит, — но уже после того, как
 * администратор подождёт загрузку.
 */
import { describe, expect, it } from 'vitest'
import {
  GALLERY_MAX_BYTES,
  type GalleryItem,
  isImage,
  moveWithin,
  reorderPlan,
  validateGalleryFile,
} from '@/lib/eventGallery'

/** Файл заданного типа и размера — без обращения к настоящему `File`. */
function fakeFile(name: string, type: string, size: number): File {
  return { name, type, size } as File
}

function item(mediaId: number, position: number, mime = 'image/jpeg'): GalleryItem {
  return {
    id: mediaId * 10,
    media_id: mediaId,
    entity_type: 'event',
    entity_id: 1,
    role: 'gallery',
    position,
    media: {
      id: mediaId,
      public_id: `pub-${mediaId}`,
      url: `https://tickets.example.com/storage/media/${mediaId}.jpg`,
      filename: `${mediaId}.jpg`,
      mime_type: mime,
      size_bytes: 1024,
      width: 800,
      height: 600,
      title: null,
      alt_text: null,
    },
  }
}

describe('validateGalleryFile', () => {
  it('принимает изображение в пределах предела', () => {
    expect(validateGalleryFile(fakeFile('a.jpg', 'image/jpeg', 1024))).toBeNull()
    expect(validateGalleryFile(fakeFile('b.webp', 'image/webp', GALLERY_MAX_BYTES))).toBeNull()
  })

  it('отклоняет тип вне списка и называет файл', () => {
    const problem = validateGalleryFile(fakeFile('payload.exe', 'application/x-msdownload', 10))

    expect(problem).not.toBeNull()
    expect(problem).toContain('payload.exe')
  })

  it('отклоняет файл больше предела', () => {
    const problem = validateGalleryFile(fakeFile('huge.jpg', 'image/jpeg', GALLERY_MAX_BYTES + 1))

    expect(problem).not.toBeNull()
    expect(problem).toContain('huge.jpg')
  })

  it('отклоняет пустой файл', () => {
    // Пустой файл проходит проверку размера (0 не больше предела) и проверку
    // типа, но записывать его бессмысленно: в галерее появится битая картинка.
    const problem = validateGalleryFile(fakeFile('empty.png', 'image/png', 0))

    expect(problem).not.toBeNull()
    expect(problem).toContain('пустой')
  })
})

describe('reorderPlan', () => {
  const items = [item(1, 0), item(2, 1), item(3, 2)]

  it('обменивает позиции перемещаемого и соседа', () => {
    expect(reorderPlan(items, 1, -1)).toEqual([
      { mediaId: 2, position: 0 },
      { mediaId: 1, position: 1 },
    ])
  })

  it('возвращает ровно две записи, а не позиции всего списка', () => {
    expect(reorderPlan(items, 0, 1)).toHaveLength(2)
  })

  it('на границе списка не возвращает ничего', () => {
    // «Выше первого» не существует. Пустой план — это отсутствие действия, и
    // вызывающий код не должен отправить ни одного запроса.
    expect(reorderPlan(items, 0, -1)).toEqual([])
    expect(reorderPlan(items, 2, 1)).toEqual([])
  })

  it('не падает на пустом списке и на индексе вне диапазона', () => {
    expect(reorderPlan([], 0, 1)).toEqual([])
    expect(reorderPlan(items, 5, 1)).toEqual([])
  })
})

describe('moveWithin', () => {
  it('возвращает новый массив, а не меняет исходный', () => {
    const source = [1, 2, 3]
    const moved = moveWithin(source, 0, 1)

    expect(moved).toEqual([2, 1, 3])
    // Мутация исходного массива сломала бы реактивность: Vue увидел бы
    // изменение там, где его не было, либо не увидел бы там, где было.
    expect(source).toEqual([1, 2, 3])
  })

  it('на границе возвращает тот же массив', () => {
    const source = [1, 2, 3]

    expect(moveWithin(source, 0, -1)).toBe(source)
    expect(moveWithin(source, 2, 1)).toBe(source)
  })
})

describe('isImage', () => {
  it('различает изображение и видео', () => {
    expect(isImage(item(1, 0, 'image/png'))).toBe(true)
    expect(isImage(item(2, 0, 'video/mp4'))).toBe(false)
  })

  it('не падает на связи без файла', () => {
    const orphan: GalleryItem = { ...item(1, 0), media: null }

    expect(isImage(orphan)).toBe(false)
  })
})
