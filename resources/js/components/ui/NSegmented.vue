<script setup lang="ts">
/**
 * Сегментный переключатель.
 *
 * Заменяет «вкладки» там, где вариантов немного: даты сеансов, фильтры списка,
 * режимы редактора. Выбранный сегмент узнаётся по фону, а не только по тексту,
 * — состояние не должно зависеть от умения различать оттенки.
 */
import { cn } from '@/lib/cn'

export interface Segment {
  value: string
  label: string
  /** Мелкая подпись — например, «осталось 42» у сеанса. */
  meta?: string
  disabled?: boolean
}

withDefaults(
  defineProps<{
    modelValue: string
    segments: Segment[]
    size?: 'sm' | 'md'
    block?: boolean
    ariaLabel?: string
  }>(),
  { size: 'md' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <div
    role="tablist"
    :aria-label="ariaLabel"
    :class="
      cn(
        'inline-flex flex-wrap gap-1 rounded-lg border border-line bg-surface-2 p-1',
        block && 'w-full',
      )
    "
  >
    <button
      v-for="segment in segments"
      :key="segment.value"
      type="button"
      role="tab"
      :aria-selected="modelValue === segment.value"
      :disabled="segment.disabled"
      :class="
        cn(
          'flex-1 rounded-md font-medium transition-all duration-120 ease-out',
          'disabled:cursor-not-allowed disabled:opacity-40',
          size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3.5 py-1.5 text-sm',
          modelValue === segment.value
            ? 'bg-brand-500 text-white shadow-sm'
            : 'text-muted hover:bg-surface-3 hover:text-content',
        )
      "
      @click="emit('update:modelValue', segment.value)"
    >
      {{ segment.label }}
      <span v-if="segment.meta" class="ml-1 opacity-70">{{ segment.meta }}</span>
    </button>
  </div>
</template>
