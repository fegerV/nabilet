<script setup lang="ts">
/**
 * Нижняя панель — мобильный ответ на боковую панель.
 *
 * На телефоне «дотянуться до верха экрана» сложнее, чем до низа, поэтому всё,
 * что требует действий, оказывается снизу: выбор мест, итог заказа, оплата.
 * Открывается жестом вверх, закрывается свайпом вниз, подложкой или Escape.
 */
import { ref, watch, onUnmounted } from 'vue'
import { cn } from '@/lib/cn'

const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    /** Высота в «шагах»: peek — виден только итог, full — раскрыт полностью. */
    snap?: 'peek' | 'full'
  }>(),
  { snap: 'peek' },
)

const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const dragOffset = ref(0)
let startY = 0
let dragging = false

function onPointerDown(event: PointerEvent): void {
  dragging = true
  startY = event.clientY
}

function onPointerMove(event: PointerEvent): void {
  if (!dragging) return
  dragOffset.value = Math.max(0, event.clientY - startY)
}

function onPointerUp(): void {
  if (!dragging) return
  dragging = false
  // Смахнули больше 90 px — закрываем. Меньше — возвращаем на место.
  if (dragOffset.value > 90) emit('update:open', false)
  dragOffset.value = 0
}

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
    <div v-if="open" class="fixed inset-0 z-sheet md:hidden">
      <div class="absolute inset-0 bg-overlay/60 animate-fade-in" @click="emit('update:open', false)" />

      <div
        :class="
          cn(
            'absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-2xl border-t border-line bg-surface shadow-xl',
            'animate-sheet-up',
          )
        "
        :style="dragOffset ? { transform: `translateY(${dragOffset}px)` } : undefined"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <div
          class="flex-none cursor-grab touch-none select-none px-4 pb-1 pt-2.5"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        >
          <div class="mx-auto h-1 w-10 rounded-full bg-line-strong" aria-hidden="true" />
          <h2 v-if="title" class="mt-2.5 text-base font-semibold text-content">{{ title }}</h2>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4 safe-bottom">
          <slot />
        </div>

        <div v-if="$slots.footer" class="flex-none border-t border-line bg-surface px-4 py-3 safe-bottom">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
