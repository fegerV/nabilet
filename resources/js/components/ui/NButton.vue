<script setup lang="ts">
/**
 * Кнопка.
 *
 * Правило иерархии: в любом окне ровно одна «главная» кнопка. Она — акцентная,
 * остальные — вторичные. Пользователь должен понимать, где единственное
 * ожидаемое действие, не читая подписи: на витрине это «Купить», в админке —
 * «Сохранить», в редакторе — «Опубликовать схему».
 */
import { computed } from 'vue'
import { cn } from '@/lib/cn'

type Variant = 'primary' | 'accent' | 'secondary' | 'outline' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'lg'

const props = withDefaults(
  defineProps<{
    variant?: Variant
    size?: Size
    loading?: boolean
    disabled?: boolean
    block?: boolean
    /** Иконка слева от подписи. */
    icon?: string
    type?: 'button' | 'submit'
    ariaLabel?: string
  }>(),
  { variant: 'primary', size: 'md', type: 'button' },
)

const emit = defineEmits<{ click: [event: MouseEvent] }>()

const VARIANTS: Record<Variant, string> = {
  primary: 'bg-brand-500 text-white hover:bg-brand-400 active:bg-brand-600 shadow-brand',
  accent: 'bg-accent-gradient text-white hover:brightness-110 active:brightness-95 shadow-accent',
  secondary: 'bg-surface-3 text-content hover:bg-line/40 active:bg-line',
  outline: 'border border-line-strong text-content hover:bg-surface-3 active:bg-line',
  ghost: 'text-muted hover:text-content hover:bg-surface-3',
  danger: 'bg-rose-500 text-white hover:bg-rose-400 active:bg-rose-600',
}

const SIZES: Record<Size, string> = {
  sm: 'h-9 px-3 text-sm gap-1.5 rounded-md',
  md: 'h-11 px-4 text-base gap-2 rounded-lg',
  lg: 'h-13 px-6 text-lg gap-2.5 rounded-lg',
}

const classes = computed(() =>
  cn(
    'relative inline-flex select-none items-center justify-center font-medium',
    'transition-all duration-120 ease-out',
    'disabled:pointer-events-none disabled:opacity-45',
    VARIANTS[props.variant],
    SIZES[props.size],
    props.block && 'w-full',
  ),
)
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled || loading"
    :aria-label="ariaLabel"
    :aria-busy="loading || undefined"
    @click="emit('click', $event)"
  >
    <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
    <span v-else-if="icon" aria-hidden="true" class="text-[1.05em] leading-none">{{ icon }}</span>
    <slot />
  </button>
</template>
