<script setup lang="ts">
/**
 * Витрина: страница собирается из секций, настроенных в админке.
 *
 * Раньше афиша была жёстко зашитой разметкой — поменять порядок блоков можно
 * было только в коде. Теперь страница — рендер конфига: состав, порядок,
 * заголовки и поведение виджетов задаёт организатор в конструкторе, а здесь
 * только обход секций.
 *
 * Если секций нет (организатор удалил все), показываем минимальную афишу:
 * пустая витрина — состояние, из которого непонятно, как вернуть блоки,
 * поэтому мы его не допускаем.
 */
import { computed, onMounted, watch } from 'vue'
import SectionShell from '@/components/storefront/widgets/SectionShell.vue'
import { isFlushWidget, widgetComponent } from '@/components/storefront/widgets/index'
import { useStorefrontStore } from '@/stores/storefront'
import { useCatalogStore } from '@/stores/catalog'
import { createSection, setting, type StorefrontSection } from '@/lib/storefront'
import { useSeo } from '@/lib/seo'

const storefront = useStorefrontStore()
const catalog = useCatalogStore()

onMounted(() => {
  void catalog.load()
  if (!storefront.config.sections.length) void storefront.load()
})

const sections = computed<StorefrontSection[]>(() => {
  // «Категории» в авто-режиме не показываем, когда у мероприятий нет жанров:
  // иначе на витрине висит заголовок «Куда пойти» и под ним ничего.
  const list = storefront.sections.filter((section) => {
    if (section.type !== 'categories') return true
    if (setting<string>(section.settings, 'source', 'auto') !== 'auto') return true
    return catalog.categories.length > 0
  })

  return list.length ? list : [createSection('hero'), createSection('posters', 'Афиша')]
})

/** Иконка сайта — из брендинга площадки. */
watch(
  () => storefront.config.branding.faviconUrl,
  (favicon) => {
    if (!favicon) return
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (!link) {
      link = document.createElement('link')
      link.rel = 'icon'
      document.head.appendChild(link)
    }
    link.href = favicon
  },
  { immediate: true },
)

/**
 * Заголовок вкладки — из брендинга площадки.
 *
 * Пока брендинг не загружен, возвращаем `null` и не трогаем документ: заголовок
 * маршрута («Афиша мероприятий») уже выставлен роутером, и мигать дефолтом
 * на каждой загрузке незачем.
 */
useSeo(() => {
  const name = storefront.config.branding.name
  return name ? { title: `${name} — афиша мероприятий` } : null
})
</script>

<template>
  <div>
    <template v-for="(section, index) in sections" :key="section.id">
      <SectionShell
        v-if="widgetComponent(section.type)"
        :title="section.title"
        :subtitle="section.subtitle"
        :flush="isFlushWidget(section.type)"
        :tight="index === sections.length - 1"
      >
        <component :is="widgetComponent(section.type)" :section="section" />
      </SectionShell>
    </template>
  </div>
</template>
