<script setup lang="ts">
/**
 * Текстовый блок.
 *
 * Никакого v-html: организатор набирает текст в конструкторе, мы выводим его
 * как текст с сохранением переносов. Поле, куда можно вставить <script>, —
 * это дыра на каждой витрине, а «хочу вставить картинку» закрывается
 * виджетами-блоками.
 */
import { computed } from 'vue'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const text = computed(() => setting<string>(props.section.settings, 'text', ''))
const align = computed(() => setting<'left' | 'center'>(props.section.settings, 'align', 'left'))
const width = computed(() => setting<'narrow' | 'wide'>(props.section.settings, 'width', 'wide'))
</script>

<template>
  <div
    v-if="text"
    :class="[
      'text-pretty whitespace-pre-line text-sm leading-relaxed text-muted sm:text-base',
      align === 'center' ? 'mx-auto text-center' : '',
      width === 'narrow' ? 'max-w-prose' : 'max-w-none',
    ]"
  >
    {{ text }}
  </div>
  <p v-else class="text-sm text-subtle">Пустой текстовый блок — добавьте текст в конструкторе.</p>
</template>
