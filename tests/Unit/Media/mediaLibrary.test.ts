/**
 * Библиотека файлов: правила, которые ломаются молча.
 *
 * Повод — раздел «Файлы» в админке. Он показывает все файлы организации, их
 * размер, размеры картинки и то, к чему файл привязан. Всё это — вычисления:
 * классификация по типу, форматирование размера, подписи связей. Ошибка в них не
 * даёт ни исключения, ни красного экрана: картинка показывается значком видео,
 * «10.0 МБ» вместо «10 МБ» никто не заметит, а «нигде не используется» вместо
 * «используется в мероприятии» приведёт к удалению нужного файла.
 *
 * Здесь же проверяется СВЯЗЬ ДВУХ СПИСКОВ ТИПОВ. Галерея мероприятия
 * (`lib/eventGallery.ts`) и серверный список (`lib/mediaLibrary.ts`) — разные
 * файлы, и они уже разъезжались: галерея предлагала mp4 и webm, а сервер
 * отвечал 422 «должен быть одного из типов: jpg, jpeg…». Тест держит их вместе.
 */
import { describe, expect, it } from 'vitest'
import { GALLERY_TYPES } from '@/lib/eventGallery'
import {
  MEDIA_ACCEPT,
  MEDIA_MAX_BYTES,
  MEDIA_TYPES,
  MEDIA_TYPES_LABEL,
  clampPage,
  dimensionsLabel,
  formatDate,
  humanSize,
  kindOf,
  type MediaAsset,
  type MediaLinkRef,
  usageLabels,
  validateMediaFile,
} from '@/lib/mediaLibrary'

/** Файл заданного типа и размера — без обращения к настоящему `File`. */
function fakeFile(name: string, type: string, size: number): File {
  return { name, type, size } as File
}

function link(entityType: string, entityId: number, role: string): MediaLinkRef {
  return { id: 1, entity_type: entityType, entity_id: entityId, role, position: 0 }
}

describe('список типов', () => {
  it('не содержит повторов', () => {
    expect(new Set(MEDIA_TYPES).size).toBe(MEDIA_TYPES.length)
  })

  it('принимает ВСЁ, что предлагает галерея мероприятия', () => {
    // Ровно этот инвариант был нарушен: `GALLERY_TYPES` содержал mp4 и webm,
    // серверный список — нет, и администратор получал 422 на файле, который
    // интерфейс только что предложил выбрать.
    for (const type of GALLERY_TYPES) {
      expect(MEDIA_TYPES).toContain(type)
    }
  })

  it('строка для `accept` собирается из того же списка', () => {
    expect(MEDIA_ACCEPT).toBe(MEDIA_TYPES.join(','))
    expect(MEDIA_ACCEPT).toContain('video/mp4')
    expect(MEDIA_TYPES_LABEL).toContain('mp4')
  })
})

describe('kindOf', () => {
  it('различает изображение, видео и документ', () => {
    expect(kindOf('image/png')).toBe('image')
    expect(kindOf('image/svg+xml')).toBe('image')
    expect(kindOf('video/mp4')).toBe('video')
    expect(kindOf('application/pdf')).toBe('document')
  })

  it('считает видео и `application/mp4`', () => {
    // Определение типа по содержимому отдаёт именно это значение для mp4 без
    // расширения, а файл, зарегистрированный через API по `path`, может прийти
    // с любым `mime_type`.
    expect(kindOf('application/mp4')).toBe('video')
  })

  it('не падает на пустом и отсутствующем типе', () => {
    expect(kindOf(null)).toBe('other')
    expect(kindOf(undefined)).toBe('other')
    expect(kindOf('')).toBe('other')
    expect(kindOf('application/octet-stream')).toBe('other')
  })

  it('не зависит от регистра', () => {
    expect(kindOf('IMAGE/PNG')).toBe('image')
  })
})

describe('humanSize', () => {
  it('форматирует размер в читаемом виде', () => {
    expect(humanSize(0)).toBe('0 Б')
    expect(humanSize(512)).toBe('512 Б')
    expect(humanSize(2048)).toBe('2 КБ')
    expect(humanSize(3 * 1024 * 1024)).toBe('3.0 МБ')
  })

  it('не показывает отрицательный и нечисловой размер', () => {
    expect(humanSize(-5)).toBe('0 Б')
    expect(humanSize(Number.NaN)).toBe('0 Б')
    expect(humanSize(Number.POSITIVE_INFINITY)).toBe('0 Б')
  })

  it('переходит на мегабайты ровно на границе', () => {
    expect(humanSize(1024 * 1024 - 1)).toBe('1024 КБ')
    expect(humanSize(1024 * 1024)).toBe('1.0 МБ')
  })
})

describe('validateMediaFile', () => {
  it('пропускает изображение, видео и документ', () => {
    expect(validateMediaFile(fakeFile('a.jpg', 'image/jpeg', 1024))).toBeNull()
    expect(validateMediaFile(fakeFile('a.mp4', 'video/mp4', 1024))).toBeNull()
    expect(validateMediaFile(fakeFile('a.pdf', 'application/pdf', 1024))).toBeNull()
  })

  it('отказывает типу вне списка', () => {
    const problem = validateMediaFile(fakeFile('a.zip', 'application/zip', 1024))

    expect(problem).toContain('a.zip')
    expect(problem).toContain('mp4')
  })

  it('отказывает файлу больше предела', () => {
    const problem = validateMediaFile(fakeFile('big.png', 'image/png', MEDIA_MAX_BYTES + 1))

    expect(problem).toContain('big.png')
    expect(problem).toContain('10.0 МБ')
  })

  it('пропускает файл ровно на пределе', () => {
    expect(validateMediaFile(fakeFile('exact.png', 'image/png', MEDIA_MAX_BYTES))).toBeNull()
  })

  it('отказывает пустому файлу', () => {
    expect(validateMediaFile(fakeFile('empty.png', 'image/png', 0))).toContain('пустой')
  })
})

describe('usageLabels', () => {
  it('переводит тип и роль на русский', () => {
    expect(usageLabels([link('event', 42, 'gallery')])).toEqual(['мероприятие #42 · галерея'])
    expect(usageLabels([link('venue', 7, 'poster')])).toEqual(['площадка #7 · афиша'])
  })

  it('показывает незнакомый тип и роль КАК ЕСТЬ', () => {
    // Список типов открыт (`entity_type` — свободная строка). Молча выбросить
    // связь значило бы написать «нигде не используется» там, где используется.
    expect(usageLabels([link('tour', 3, 'banner')])).toEqual(['tour #3 · banner'])
  })

  it('возвращает пустой список, когда связей нет или они не запрашивались', () => {
    expect(usageLabels([])).toEqual([])
    expect(usageLabels(undefined)).toEqual([])
  })

  it('сохраняет порядок связей', () => {
    const labels = usageLabels([link('event', 1, 'gallery'), link('hall', 2, 'cover')])

    expect(labels).toEqual(['мероприятие #1 · галерея', 'зал #2 · обложка'])
  })
})

describe('dimensionsLabel', () => {
  it('склеивает ширину и высоту', () => {
    expect(dimensionsLabel({ width: 1200, height: 800 })).toBe('1200×800')
  })

  it('пустая строка, если размеров нет', () => {
    // Для PDF, видео и SVG `getimagesize()` не отрабатывает — это нормальный
    // случай, а не «0×0».
    expect(dimensionsLabel({ width: null, height: 800 })).toBe('')
    expect(dimensionsLabel({ width: 1200, height: null })).toBe('')
    expect(dimensionsLabel({ width: null, height: null })).toBe('')
  })
})

describe('formatDate', () => {
  it('пустая строка вместо отсутствующей или битой даты', () => {
    expect(formatDate(null)).toBe('')
    expect(formatDate(undefined)).toBe('')
    expect(formatDate('')).toBe('')
    expect(formatDate('не дата')).toBe('')
  })

  it('показывает год для настоящей даты', () => {
    // Точный день не проверяется: он зависит от часового пояса, в котором
    // прогоняется тест, и такая проверка падала бы на чужой машине.
    expect(formatDate('2026-10-09T12:00:00Z')).toContain('2026')
  })
})

describe('clampPage', () => {
  it('прижимает страницу к последней существующей', () => {
    // После удаления последнего файла на последней странице текущая становится
    // пустой, и это выглядит как «файлы пропали».
    expect(clampPage(5, 3)).toBe(3)
  })

  it('не пускает страницу ниже первой', () => {
    expect(clampPage(0, 3)).toBe(1)
    expect(clampPage(-2, 3)).toBe(1)
    expect(clampPage(Number.NaN, 3)).toBe(1)
  })

  it('не меняет допустимую страницу', () => {
    expect(clampPage(2, 3)).toBe(2)
  })

  it('возвращает первую, когда страниц нет вовсе', () => {
    expect(clampPage(4, 0)).toBe(1)
    expect(clampPage(4, Number.NaN)).toBe(1)
  })

  it('отбрасывает дробную часть', () => {
    expect(clampPage(2.9, 5)).toBe(2)
  })
})

describe('MediaAsset', () => {
  it('различает «связей нет» и «связи не запрашивались»', () => {
    const base: MediaAsset = {
      id: 1,
      public_id: 'x',
      disk: 'public',
      path: 'media/a.png',
      url: '/storage/media/a.png',
      filename: 'a.png',
      mime_type: 'image/png',
      size_bytes: 10,
      width: 1,
      height: 1,
      checksum: null,
      title: null,
      alt_text: null,
      variants_json: null,
      created_at: null,
      updated_at: null,
    }

    // Оба случая дают пустой список подписей, но тип обязан допускать `undefined`
    // как отдельное состояние — иначе «неизвестно» будет показано как «нигде не
    // используется».
    expect(usageLabels(base.links)).toEqual([])
    expect(usageLabels([...([] as MediaLinkRef[])])).toEqual([])
  })
})
