/**
 * Каталог ачивок «Клуба зрителей» (демо-данные кабинета).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ФАЙЛ
 *   Экран кабинета — единственное место, где читается весь каталог сразу.
 *   Когда появится модуль `Achievements` (бриф, фаза 1), этот файл станет
 *   точкой, куда подставится ответ `/me/achievements`: форма данных уже
 *   совпадает с тем, что вернёт сервер (id, название, статус, прогресс).
 *
 * ДАННЫЕ — ДЕМО
 *   Бэкенда ачивок ещё нет (бриф — стратегия, не код). Состояния ниже
 *   повторяют «демо-числа» из брифа (§4), чтобы макет кабинета был живым:
 *   часть получена, часть в пути, одна — скрытая. Баланс бонусов в шапке
 *   берётся из реального стора (`stores/loyalty`), а не отсюда.
 *
 * ЭМБЛЕМЫ — СТАТИЧЕСКИЙ SVG
 *   `glyphs` — внутренний SVG-разметка (viewBox 0 0 24 24), без внешних
 *   файлов и картинок, как требует бриф (§4). Строки статичны и принадлежат
 *   проекту — рендерим через `v-html` только их, без интерполяции
 *   пользовательского ввода.
 */
export type AchievementCategory = 'visits' | 'music' | 'theatre' | 'discovery' | 'season'

export type AchievementStatus = 'earned' | 'progress' | 'hidden'

export interface Achievement {
  id: string
  name: string
  category: AchievementCategory
  /** Ключ эмблемы из `glyphs`. */
  icon: string
  /** Условие простыми словами (не техническое). */
  description: string
  /** Что даётся. */
  reward: string
  status: AchievementStatus
  /** Для `progress`: сколько сделано и сколько нужно. */
  progress?: { current: number; target: number }
  /** Для `earned`: демо-дата получения. */
  earnedAt?: string
  /** «Скрытая» ачивка — условие не раскрываем (эффект открытия, бриф §8.5). */
  hidden?: boolean
  /** Помечена как «только что разблокирована» — на неё срабатывает анимация. */
  celebrate?: boolean
}

export const CATEGORY_LABEL: Record<AchievementCategory, string> = {
  visits: 'Посещения',
  music: 'Музыка',
  theatre: 'Театр',
  discovery: 'Новые жанры',
  season: 'Сезон',
}

/** Цвет метки категории (тон `NBadge`). */
export const CATEGORY_TONE: Record<AchievementCategory, 'brand' | 'accent' | 'rose' | 'sky' | 'sun'> = {
  visits: 'brand',
  music: 'accent',
  theatre: 'rose',
  discovery: 'sky',
  season: 'sun',
}

/**
 * Внутренний SVG (viewBox 0 0 24 24). `currentColor` наследует цвет медали.
 * Статично, принадлежит проекту — рендер через `v-html` безопасен.
 */
export const glyphs: Record<string, string> = {
  star: '<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9 6.8 19.9l1-5.8L3.5 9.7l5.9-.9z" fill="currentColor"/>',
  spark:
    '<path d="M12 3v6M12 15v6M3 12h6M15 12h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 7l1.4 3.6L17 12l-3.6 1.4L12 17l-1.4-3.6L7 12l3.6-1.4z" fill="currentColor"/>',
  crown:
    '<path d="M4 8l3.5 3L12 6l4.5 5L20 8l-1.5 9h-13z" fill="currentColor"/><rect x="5" y="18.5" width="14" height="2" rx="1" fill="currentColor"/>',
  trophy:
    '<path d="M7 4h10v4a5 5 0 0 1-10 0z" fill="currentColor"/><path d="M7 5H4v2a3 3 0 0 0 3 3M17 5h3v2a3 3 0 0 1-3 3" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M10 13h4v3h-4zM9.5 20h5v-4h-5z" fill="currentColor"/>',
  music:
    '<path d="M9 18V6l10-2v12" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/><circle cx="7" cy="18" r="2.1" fill="currentColor"/><circle cx="17" cy="16" r="2.1" fill="currentColor"/>',
  bolt: '<path d="M13 2L4 14h6l-1 8 9-12h-6z" fill="currentColor"/>',
  grid:
    '<rect x="4" y="4" width="7" height="7" rx="1.5" fill="currentColor"/><rect x="13" y="4" width="7" height="7" rx="1.5" fill="currentColor" opacity="0.45"/><rect x="4" y="13" width="7" height="7" rx="1.5" fill="currentColor" opacity="0.45"/><rect x="13" y="13" width="7" height="7" rx="1.5" fill="currentColor"/>',
  wave:
    '<path d="M3 12c2-4 4-4 6 0s4 4 6 0 4-4 6 0" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/><path d="M3 17c2-3 4-3 6 0s4 3 6 0 4-3 6 0" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" opacity="0.45"/>',
  masks:
    '<path d="M4 5c3 0 4 3 4 7s-1 7-4 7c0-3 0-11 0-14z" fill="currentColor"/><path d="M20 5c-3 0-4 3-4 7s1 7 4 7c0-3 0-11 0-14z" fill="currentColor" opacity="0.5"/>',
  drama:
    '<circle cx="8" cy="12" r="4" fill="currentColor" opacity="0.5"/><path d="M6.4 13.2a2.6 2.6 0 0 0 3.2 0" stroke="#fff" stroke-width="1.3" fill="none" stroke-linecap="round"/><circle cx="16" cy="12" r="4" fill="currentColor"/><path d="M14.4 10.8a2.6 2.6 0 0 0 3.2 0" stroke="#fff" stroke-width="1.3" fill="none" stroke-linecap="round"/>',
  hall:
    '<path d="M4 20V6l8-3 8 3v14" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M9 20v-5h6v5" fill="currentColor"/><path d="M4 9.5h16" stroke="currentColor" stroke-width="1.4"/>',
  compass:
    '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M15.2 8.8l-2.2 5-4 1.4 2.2-5z" fill="currentColor"/><circle cx="12" cy="12" r="1.3" fill="currentColor"/>',
  rocket:
    '<path d="M12 3c3 2 5 6 5 11l-3 3h-4l-3-3c0-5 2-9 5-11z" fill="currentColor"/><circle cx="12" cy="10" r="1.7" fill="#fff"/><path d="M9 17l-2 3 3-1M15 17l2 3-3-1" stroke="currentColor" stroke-width="1.4" fill="none" stroke-linecap="round"/>',
  calendar:
    '<rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M4 9.5h16M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="14.5" r="1.6" fill="currentColor"/>',
  moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4 6.5 6.5 0 0 0 20 14.5z" fill="currentColor"/>',
}

/** Каталог MVP из 15 ачивок (бриф §5). Состояния — демо, см. комментарий файла. */
export const ACHIEVEMENTS: Achievement[] = [
  {
    id: 'first-step',
    name: 'Начало истории',
    category: 'visits',
    icon: 'star',
    description: 'Первое посещённое событие',
    reward: '+100 приветственных бонусов',
    status: 'earned',
    earnedAt: '2026-04-12',
  },
  {
    id: 'getting-taste',
    name: 'Вхожу во вкус',
    category: 'visits',
    icon: 'spark',
    description: '5 посещённых событий',
    reward: 'Персональный промокод',
    status: 'progress',
    progress: { current: 2, target: 5 },
  },
  {
    id: 'regular',
    name: 'Завсегдатай',
    category: 'visits',
    icon: 'crown',
    description: '10 посещённых событий',
    reward: 'Статус клуба',
    status: 'progress',
    progress: { current: 3, target: 10 },
  },
  {
    id: 'legend',
    name: 'Легенда зала',
    category: 'visits',
    icon: 'trophy',
    description: '25 посещённых событий',
    reward: 'VIP + ранний доступ',
    status: 'progress',
    progress: { current: 3, target: 25 },
  },
  {
    id: 'meloman',
    name: 'Меломан',
    category: 'music',
    icon: 'music',
    description: '3 концерта в любых жанрах',
    reward: 'Скидка на концерты',
    status: 'earned',
    earnedAt: '2026-06-03',
  },
  {
    id: 'rock-alive',
    name: 'Рок не умер',
    category: 'music',
    icon: 'bolt',
    description: '3 рок-концерта',
    reward: 'Подборка рок-событий',
    status: 'progress',
    progress: { current: 2, target: 3 },
  },
  {
    id: 'all-genres',
    name: 'Все жанры хороши',
    category: 'music',
    icon: 'grid',
    description: '4 разных музыкальных жанра',
    reward: 'Ранний доступ',
    status: 'earned',
    earnedAt: '2026-07-19',
  },
  {
    id: 'same-wave',
    name: 'На одной волне',
    category: 'music',
    icon: 'wave',
    description: '3 концерта одного артиста',
    reward: 'Закрытый розыгрыш',
    status: 'progress',
    progress: { current: 1, target: 3 },
  },
  {
    id: 'theatregoer',
    name: 'Театрал',
    category: 'theatre',
    icon: 'masks',
    description: '5 спектаклей',
    reward: 'Промокод',
    status: 'progress',
    progress: { current: 2, target: 5 },
  },
  {
    id: 'backstage',
    name: 'За кулисами жанров',
    category: 'theatre',
    icon: 'drama',
    description: 'Спектакли трёх видов: комедия, драма, мюзикл',
    reward: 'Доступ к премьерам',
    status: 'earned',
    earnedAt: '2026-08-08',
  },
  {
    id: 'stage-expert',
    name: 'Знаток сцены',
    category: 'theatre',
    icon: 'hall',
    description: '3 разных театра',
    reward: 'Спецпоказы',
    status: 'progress',
    progress: { current: 1, target: 3 },
  },
  {
    id: 'explorer',
    name: 'Охотник за открытиями',
    category: 'discovery',
    icon: 'compass',
    description: 'Концерт артиста, которого раньше не было',
    reward: 'Бонусы',
    status: 'progress',
    progress: { current: 0, target: 1 },
  },
  {
    id: 'open-talents',
    name: 'Открыватель талантов',
    category: 'discovery',
    icon: 'rocket',
    description: 'Артист впервые выступает на площадке',
    reward: 'Бонусы',
    status: 'earned',
    earnedAt: '2026-10-11',
    celebrate: true,
  },
  {
    id: 'culture-marathon',
    name: 'Культурный марафон',
    category: 'season',
    icon: 'calendar',
    description: '3 события за один месяц',
    reward: '×1.5 бонусов на покупку',
    status: 'progress',
    progress: { current: 1, target: 3 },
  },
  {
    id: 'nightlife',
    name: 'Ночная жизнь',
    category: 'season',
    icon: 'moon',
    description: 'Скрытая ачивка',
    reward: 'Скрытый статус',
    status: 'hidden',
    hidden: true,
  },
]
