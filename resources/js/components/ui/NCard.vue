<script setup lang="ts">
/**
 * Карточка — базовый строительный блок админки.
 *
 * Раньше каждый раздел заводил свой «.surface-card» с собственным заголовком и
 * отступами, поэтому карточки слегка разъезжались: у одной заголовок на
 * py-3, у другой — на py-4, у третьей и вовсе без рамки. NCard фиксирует
 * единый скелет — заголовок (иконка + заголовок + описание + действия), тело,
 * подвал — и одинаковые отступы, чтобы вся админка читалась как один продукт.
 *
 * Таблицы (NDataTable) уже обёрнуты в собственную карточку — их в NCard класть
 * не надо, иначе получится «карточка в карточке».
 */
import { computed, useSlots } from 'vue'
import { cn } from '@/lib/cn'

const props = withDefaults(
  defineProps<{
    title?: string
    description?: string
    /** Короткая пиктограмма слева в заголовке. */
    icon?: string
    /** Отступ тела. `none` — когда внутри свой контент с границами (список). */
    padding?: 'none' | 'sm' | 'md' | 'lg'
    /** Поднятие при наведении: для кликабельных карточек-ссылок. */
    hover?: boolean
    /** Рамка. false — «плавающая» карточка на цветном фоне. */
    bordered?: boolean
    as?: string
  }>(),
  { padding: 'md', bordered: true, as: 'section' },
)

const slots = useSlots()
const hasHeader = computed(() => Boolean(props.title || slots.header || slots.actions))
const hasFooter = computed(() => Boolean(slots.footer))

const PADDING = {
  none: '',
  sm: 'p-4',
  md: 'p-4 sm:p-5',
  lg: 'p-6 sm:p-7',
} as const
</script>

<template>
  <component
    :is="as"
    :class="
      cn(
        'rounded-xl bg-surface',
        bordered ? 'border border-line' : '',
        hover
          ? 'shadow-sm transition-all duration-200 ease-out hover:-translate-y-0.5 hover:shadow-md'
          : 'shadow-sm',
      )
    "
  >
    <header
      v-if="hasHeader"
      class="flex items-start justify-between gap-3 border-b border-line px-4 py-3 sm:px-5"
    >
      <slot name="header">
        <div class="flex min-w-0 items-start gap-3">
          <span
            v-if="icon"
            aria-hidden="true"
            class="grid h-9 w-9 flex-none place-items-center rounded-lg bg-surface-3 text-lg text-brand-400"
          >{{ icon }}</span>
          <div class="min-w-0">
            <h2 v-if="title" class="font-display text-base font-semibold tracking-tight text-content">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-xs text-subtle">{{ description }}</p>
          </div>
        </div>
      </slot>
      <div v-if="slots.actions" class="flex flex-none items-center gap-2">
        <slot name="actions" />
      </div>
    </header>

    <div :class="PADDING[padding]">
      <slot />
    </div>

    <footer v-if="hasFooter" class="border-t border-line px-4 py-3 sm:px-5">
      <slot name="footer" />
    </footer>
  </component>
</template>
