/**
 * Конструктор витрины: типы, дефолты и справочник виджетов.
 *
 * Дефолты дублируют StorefrontService::defaultConfig() на бэкенде — витрина
 * обязана что-то показать и когда API недоступен (недоступная админка не
 * должна превращать афишу в белый экран). Бэкенд остаётся источником правды:
 * он нормализует присланное и отбрасывает всё, чего нет в белом списке.
 */

export type SectionType =
  | 'hero'
  | 'posters'
  | 'featured'
  | 'categories'
  | 'countdown'
  | 'promo'
  | 'richtext'
  | 'faq'
  | 'stats'
  | 'subscribe'

export interface ThemeConfig {
  preset: string
  brand: string
  brandStrong: string
  accent: string
  accentStrong: string
  radius: number
  mode: 'light' | 'dark' | 'auto'
  surface: 'tinted' | 'clean' | 'dark'
}

export interface BrandingConfig {
  name: string
  tagline: string
  logoUrl: string
  logoMark: string
  faviconUrl: string
}

export interface LinkItem {
  label: string
  to?: string
  url?: string
}

export interface HeaderConfig {
  showSearch: boolean
  showCart: boolean
  showThemeToggle: boolean
  nav: LinkItem[]
}

export interface FooterConfig {
  text: string
  links: LinkItem[]
  social: LinkItem[]
}

export interface StorefrontSection {
  id: string
  type: SectionType
  visible: boolean
  title: string
  subtitle: string
  settings: Record<string, unknown>
}

export interface StorefrontConfig {
  version: number
  theme: ThemeConfig
  branding: BrandingConfig
  header: HeaderConfig
  footer: FooterConfig
  sections: StorefrontSection[]
}

export interface Palette {
  brand: string
  brandStrong: string
  accent: string
  accentStrong: string
}

/** Готовые цветовые схемы. Ключи совпадают с StorefrontService::PRESETS. */
export const PALETTES: Record<string, { label: string; colors: Palette }> = {
  violet: { label: 'Электрик', colors: { brand: '#6D4AFF', brandStrong: '#5A31F0', accent: '#FF5C22', accentStrong: '#F03F00' } },
  indigo: { label: 'Индиго', colors: { brand: '#3B5BDB', brandStrong: '#2F49AF', accent: '#F76707', accentStrong: '#D9480F' } },
  emerald: { label: 'Изумруд', colors: { brand: '#0CA678', brandStrong: '#087F5B', accent: '#F08C00', accentStrong: '#C76A00' } },
  crimson: { label: 'Кармин', colors: { brand: '#E03131', brandStrong: '#C92A2A', accent: '#1C7ED6', accentStrong: '#1971C2' } },
  graphite: { label: 'Графит', colors: { brand: '#343A40', brandStrong: '#212529', accent: '#F59F00', accentStrong: '#E67700' } },
  lagoon: { label: 'Лагуна', colors: { brand: '#1098AD', brandStrong: '#0C8599', accent: '#D6336C', accentStrong: '#A61E4D' } },
}

export interface WidgetMeta {
  type: SectionType
  label: string
  icon: string
  hint: string
  /** Можно ли добавить второй такой же виджет на страницу. */
  once: boolean
}

export const WIDGETS: WidgetMeta[] = [
  { type: 'hero', label: 'Герой-баннер', icon: '◈', hint: 'Первый экран: заголовок, подзаголовок, кнопка', once: true },
  { type: 'posters', label: 'Афиша', icon: '▦', hint: 'Сетка мероприятий с фильтрами по категориям', once: false },
  { type: 'featured', label: 'Подборка', icon: '★', hint: 'Карусель или сетка выбранных событий', once: false },
  { type: 'categories', label: 'Категории', icon: '◍', hint: 'Чипсы или плитки жанров', once: false },
  { type: 'countdown', label: 'Обратный отсчёт', icon: '◷', hint: 'Таймер до ближайшего события', once: false },
  { type: 'promo', label: 'Промо-полоса', icon: '◊', hint: 'Акция, промокод или призыв к действию', once: false },
  { type: 'richtext', label: 'Текст', icon: '¶', hint: 'Свободный абзац: о площадке, правила, оферта', once: false },
  { type: 'faq', label: 'Вопросы', icon: '?', hint: 'Раскрывающийся список вопросов и ответов', once: false },
  { type: 'stats', label: 'Цифры', icon: '▲', hint: 'Показатели: мероприятия, города, билеты', once: false },
  { type: 'subscribe', label: 'Подписка', icon: '✉', hint: 'Форма сбора e-mail на рассылку афиши', once: false },
]

export const WIDGET_LABELS: Record<string, string> = Object.fromEntries(
  WIDGETS.map((w) => [w.type, w.label]),
)

let sectionSeq = 0

/** id секции: стабильный, чтобы Vue не терял состояние и DnD не «прыгал». */
export function newSectionId(type: SectionType): string {
  sectionSeq += 1
  return `sec_${type}_${Date.now().toString(36)}${sectionSeq.toString(36)}`
}

/** Поля виджета по умолчанию — то, что видит организатор сразу после «Добавить». */
export function defaultSettings(type: SectionType): Record<string, unknown> {
  switch (type) {
    case 'hero':
      return {
        badge: 'Билеты без наценки за кассу',
        title: 'Выберите событие — место найдём на схеме зала',
        subtitle: 'Реальная рассадка, честные цены и билет с QR.',
        ctaLabel: 'Смотреть афишу',
        ctaLink: '/',
        image: '',
        align: 'left',
        height: 'medium',
        showSearch: true,
      }
    case 'posters':
      return { layout: 'grid', columns: 3, limit: 12, sort: 'date', category: '', showFilters: true }
    case 'featured':
      return { source: 'upcoming', limit: 6, layout: 'carousel', ids: [] }
    case 'categories':
      return { source: 'auto', style: 'chips', items: [] }
    case 'countdown':
      return { eventId: '', target: '', label: 'До начала' }
    case 'promo':
      return { tone: 'brand', title: '', text: '', buttonLabel: '', buttonLink: '/', code: '' }
    case 'richtext':
      return { text: '', align: 'left', width: 'wide' }
    case 'faq':
      return { items: [] }
    case 'stats':
      return { source: 'auto', items: [] }
    case 'subscribe':
      return {
        title: 'Узнавайте о событиях первыми',
        text: 'Рассказываем о премьерах и открытых продажах.',
        placeholder: 'E-mail',
        buttonLabel: 'Подписаться',
        privacy: '',
      }
    default:
      return {}
  }
}

export function createSection(type: SectionType, title = ''): StorefrontSection {
  return {
    id: newSectionId(type),
    type,
    visible: true,
    title,
    subtitle: '',
    settings: defaultSettings(type),
  }
}

/** Дефолтная витрина: то же, что отдаёт бэкенд до первого сохранения. */
export function defaultConfig(): StorefrontConfig {
  return {
    version: 1,
    theme: {
      preset: 'violet',
      brand: '#6D4AFF',
      brandStrong: '#5A31F0',
      accent: '#FF5C22',
      accentStrong: '#F03F00',
      radius: 16,
      mode: 'auto',
      surface: 'tinted',
    },
    branding: {
      name: 'NABILET',
      tagline: 'Билеты на события',
      logoUrl: '',
      logoMark: 'Н',
      faviconUrl: '',
    },
    header: {
      showSearch: true,
      showCart: true,
      showThemeToggle: true,
      nav: [
        { label: 'Афиша', to: '/' },
        { label: 'Мои билеты', to: '/tickets' },
        { label: 'Организаторам', to: '/admin' },
      ],
    },
    footer: {
      text: 'NABILET — билеты на события без наценки за кассу.',
      links: [
        { label: 'Помощь', url: '/' },
        { label: 'Возврат', url: '/' },
        { label: 'Организаторам', url: '/admin' },
      ],
      social: [],
    },
    sections: [
      {
        ...createSection('hero'),
        settings: defaultSettings('hero'),
      },
      { ...createSection('categories'), title: 'Куда пойти' },
      { ...createSection('posters'), title: 'Афиша', subtitle: 'Ближайшие мероприятия' },
      { ...createSection('featured'), title: 'Рекомендуем', subtitle: 'То, что стоит увидеть' },
      { ...createSection('promo') },
      { ...createSection('stats') },
      { ...createSection('subscribe') },
    ],
  }
}

/** Аккуратное чтение поля настроек: конфиг приходит извне, типы не гарантированы. */
export function setting<T>(settings: Record<string, unknown>, key: string, fallback: T): T {
  const value = settings?.[key]
  if (value === undefined || value === null || value === '') return fallback
  return value as T
}

/** Глубокое копирование: конструктор мутирует копию, а не загруженный конфиг. */
export function cloneConfig(config: StorefrontConfig): StorefrontConfig {
  return JSON.parse(JSON.stringify(config)) as StorefrontConfig
}
