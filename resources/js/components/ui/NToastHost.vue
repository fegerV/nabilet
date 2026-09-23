<script setup lang="ts">
/**
 * Уведомления.
 *
 * Появляются справа сверху на десктопе и сверху на всю ширину в мобильной
 * версии — там правый край недоступен для большого пальца. Успех оплаты
 * остаётся дольше остальных: это единственное сообщение, которое пользователь
 * действительно хочет дочитать.
 */
import { useUiStore } from '@/stores/ui'
import { cn } from '@/lib/cn'

const ui = useUiStore()

const TONES = {
  neutral: 'border-line bg-surface',
  mint: 'border-mint-500/40 bg-mint-500/10',
  rose: 'border-rose-500/40 bg-rose-500/10',
  sun: 'border-sun-500/40 bg-sun-500/10',
  brand: 'border-brand-500/40 bg-brand-500/10',
} as const

const ICONS = { neutral: 'ℹ', mint: '✓', rose: '!', sun: '⏱', brand: '★' } as const
</script>

<template>
  <div
    class="pointer-events-none fixed inset-x-0 top-32 z-toast flex flex-col items-center gap-2 p-3 sm:inset-x-auto sm:right-0 sm:items-end sm:p-4"
    role="region"
    aria-live="polite"
    aria-label="Уведомления"
  >
    <TransitionGroup
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 -translate-y-2"
      leave-active-class="transition duration-120 ease-in absolute"
      leave-to-class="opacity-0 scale-95"
      move-class="transition duration-200"
    >
      <div
        v-for="toast in ui.toasts"
        :key="toast.id"
        :class="
          cn(
            'pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border px-3.5 py-3 shadow-md backdrop-blur',
            TONES[toast.tone],
          )
        "
      >
        <span class="mt-0.5 flex-none text-sm" aria-hidden="true">{{ ICONS[toast.tone] }}</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium text-content">{{ toast.title }}</p>
          <p v-if="toast.description" class="mt-0.5 text-xs text-muted text-pretty">{{ toast.description }}</p>
        </div>
        <button
          type="button"
          class="-mr-1 -mt-0.5 flex-none text-subtle transition-colors hover:text-content"
          aria-label="Скрыть уведомление"
          @click="ui.dismiss(toast.id)"
        >✕</button>
      </div>
    </TransitionGroup>
  </div>
</template>
