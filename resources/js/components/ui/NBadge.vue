<script setup lang="ts">
/** Метка-плашка. Для короткого статуса, счётчика, категории. */
import type { Tone } from '@/lib/types'
import { cn } from '@/lib/cn'

const props = withDefaults(
  defineProps<{
    tone?: Tone
    size?: 'sm' | 'md'
    /** Точка слева: для статусов-процессов («идёт», «ждём»). */
    dot?: boolean
  }>(),
  { tone: 'neutral', size: 'sm' },
)

const TONES: Record<Tone, string> = {
  neutral: 'bg-surface-3 text-muted',
  brand: 'bg-brand-500/14 text-brand-300 ring-1 ring-brand-500/25',
  accent: 'bg-accent-500/14 text-accent-300 ring-1 ring-accent-500/25',
  sun: 'bg-sun-500/14 text-sun-400 ring-1 ring-sun-500/25',
  mint: 'bg-mint-500/14 text-mint-400 ring-1 ring-mint-500/25',
  rose: 'bg-rose-500/14 text-rose-400 ring-1 ring-rose-500/25',
  sky: 'bg-sky-500/14 text-sky-400 ring-1 ring-sky-500/25',
}

const DOT_TONES: Record<Tone, string> = {
  neutral: 'bg-content-subtle',
  brand: 'bg-brand-400',
  accent: 'bg-accent-400',
  sun: 'bg-sun-400',
  mint: 'bg-mint-400',
  rose: 'bg-rose-400',
  sky: 'bg-sky-400',
}
</script>

<template>
  <span
    :class="
      cn(
        'inline-flex items-center gap-1.5 rounded-full font-medium',
        size === 'sm' ? 'px-2 py-0.5 text-2xs' : 'px-2.5 py-1 text-xs',
        TONES[props.tone],
      )
    "
  >
    <span v-if="dot" :class="cn('h-1.5 w-1.5 rounded-full', DOT_TONES[props.tone])" aria-hidden="true" />
    <slot />
  </span>
</template>
