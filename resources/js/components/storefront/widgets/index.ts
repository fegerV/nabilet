/**
 * Реестр виджетов витрины.
 *
 * Страница не знает конкретных компонентов: она берёт тип секции из конфига
 * и спрашивает компонент здесь. Добавить виджет = положить файл, зарегистрировать
 * в WIDGETS (lib/storefront.ts) и добавить строчку в этот реестр — страница
 * и конструктор подхватят его без правок.
 */
import type { Component } from 'vue'
import HeroWidget from './HeroWidget.vue'
import PosterGridWidget from './PosterGridWidget.vue'
import FeaturedWidget from './FeaturedWidget.vue'
import CategoriesWidget from './CategoriesWidget.vue'
import CountdownWidget from './CountdownWidget.vue'
import PromoWidget from './PromoWidget.vue'
import RichTextWidget from './RichTextWidget.vue'
import FaqWidget from './FaqWidget.vue'
import StatsWidget from './StatsWidget.vue'
import SubscribeWidget from './SubscribeWidget.vue'
import type { SectionType } from '@/lib/storefront'

export const WIDGET_COMPONENTS: Record<SectionType, Component> = {
  hero: HeroWidget,
  posters: PosterGridWidget,
  featured: FeaturedWidget,
  categories: CategoriesWidget,
  countdown: CountdownWidget,
  promo: PromoWidget,
  richtext: RichTextWidget,
  faq: FaqWidget,
  stats: StatsWidget,
  subscribe: SubscribeWidget,
}

/** Полноширинные виджеты: им нужен фон во всё окно, а не колонка контейнера. */
export const FLUSH_WIDGETS: SectionType[] = ['hero']

export function widgetComponent(type: SectionType): Component | null {
  return WIDGET_COMPONENTS[type] ?? null
}

export function isFlushWidget(type: SectionType): boolean {
  return FLUSH_WIDGETS.includes(type)
}
