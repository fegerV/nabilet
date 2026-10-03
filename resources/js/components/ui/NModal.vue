<script setup lang="ts">
/**
 * Модальное окно.
 *
 * Применяется только там, где контекст обязан остаться на месте — подтверждение
 * возврата, отмена сеанса, публикация схемы. Для всего остального в мобильной
 * версии есть нижняя панель: она не вырывает пользователя из контекста.
 */
import { watch, onUnmounted } from 'vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    description?: string
    size?: 'sm' | 'md' | 'lg'
    /** Опасное действие: рамка и заголовок становятся предупреждающими. */
    destructive?: boolean
  }>(),
  { size: 'md' },
)

const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const SIZES = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-2xl' }

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && props.open) emit('update:open', false)
}

watch(
  () => props.open,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
    if (open) window.addEventListener('keydown', onKeydown)
    else window.removeEventListener('keydown', onKeydown)
  },
)

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-modal grid place-items-center p-4">
      <div class="absolute inset-0 bg-overlay/70 animate-fade-in" @click="emit('update:open', false)" />

      <div
        :class="[
          'relative w-full animate-scale-in rounded-xl border border-line bg-surface shadow-xl',
          destructive ? 'ring-1 ring-rose-500/30' : '',
          SIZES[size],
        ]"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <div class="flex items-start gap-3 px-5 pb-3 pt-5">
          <div class="min-w-0 flex-1">
            <h2
              :class="['text-lg font-semibold', destructive ? 'text-rose-400' : 'text-content']"
            >{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-muted text-pretty">{{ description }}</p>
          </div>
          <button
            type="button"
            class="-mr-1 -mt-1 flex h-8 w-8 flex-none items-center justify-center rounded-md text-subtle transition-colors hover:bg-surface-3 hover:text-content"
            aria-label="Закрыть"
            @click="emit('update:open', false)"
          >
            ✕
          </button>
        </div>

        <div v-if="$slots.default" class="px-5 pb-5">
          <slot />
        </div>

        <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-line px-5 py-4">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
