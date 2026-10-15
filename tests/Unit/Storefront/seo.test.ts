import { describe, expect, it, beforeEach } from 'vitest'
import { nextTick, ref } from 'vue'
import { applySeo, useSeo } from '@/lib/seo'

/** Прочитать content тега (или null, если тега нет). */
function meta(attr: 'name' | 'property', key: string): string | null {
  return document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)?.getAttribute('content') ?? null
}

function countMeta(attr: 'name' | 'property', key: string): number {
  return document.head.querySelectorAll(`meta[${attr}="${key}"]`).length
}

beforeEach(() => {
  document.head.innerHTML = ''
  document.title = ''
})

describe('applySeo', () => {
  it('ставит заголовок, description и og-теги', () => {
    applySeo({ title: 'Концерт — билеты', description: 'Описание события', url: 'http://localhost/#/event/koncert' })

    expect(document.title).toBe('Концерт — билеты')
    expect(meta('name', 'description')).toBe('Описание события')
    expect(meta('property', 'og:title')).toBe('Концерт — билеты')
    expect(meta('property', 'og:description')).toBe('Описание события')
    expect(meta('property', 'og:url')).toBe('http://localhost/#/event/koncert')
  })

  it('без данных откатывается к значениям сайта, а не оставляет чужой текст', () => {
    applySeo({ title: 'Событие', description: 'Описание события' })
    // Переход на нейтральную страницу: ничего не передано.
    applySeo({ title: 'Оформление заказа' })

    expect(document.title).toBe('Оформление заказа')
    expect(meta('name', 'description')).not.toBe('Описание события')
    expect(meta('name', 'description')).toContain('NABILET')
  })

  it('не плодит дубликаты тегов при повторных вызовах', () => {
    applySeo({ title: 'Раз', description: 'А' })
    applySeo({ title: 'Два', description: 'Б' })

    expect(countMeta('name', 'description')).toBe(1)
    expect(countMeta('property', 'og:title')).toBe(1)
    expect(meta('name', 'description')).toBe('Б')
  })

  it('абсолютизирует относительный путь картинки и убирает тег без картинки', () => {
    applySeo({ title: 'Событие', image: '/storage/poster.jpg' })
    expect(meta('property', 'og:image')).toBe('http://localhost/storage/poster.jpg')

    applySeo({ title: 'Событие' })
    expect(meta('property', 'og:image')).toBeNull()
  })

  it('игнорирует пустые строки как отсутствие значения', () => {
    applySeo({ title: '   ', description: '' })

    expect(document.title).toBe('NABILET — билеты на события')
    expect(meta('name', 'description')).toContain('NABILET')
  })
})

describe('useSeo', () => {
  it('применяет метаданные реактивно и пропускает null', async () => {
    const source = ref<{ title: string } | null>(null)
    useSeo(() => source.value)

    // Данных ещё нет — документ не трогаем.
    expect(document.title).toBe('')

    source.value = { title: 'Позже' }
    await nextTick()

    expect(document.title).toBe('Позже')
  })
})
