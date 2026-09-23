<script setup lang="ts">
/**
 * Счётчик количества — для зон со свободной рассадкой (standing zones).
 *
 * Реализует правило из ТЗ §7: количество запрашивается явно, а не «сколько
 * влезет». Лимит берётся из остатка инвентаря, поэтому кнопка «+» перестаёт
 * работать раньше, чем сервер вернёт ошибку.
 */
import { cn } from '@/lib/cn'
import { plural } from '@/lib/format'

const props = withDefaults(
  defineProps<{
    modelValue: number
    min?: number
    max?: number
    label?: string
    unit?: [string, string, string]
    hint?: string
  }>(),
  { min: 0, max: 10, unit: () => ['билет', 'билета', 'билетов'] },
)

const emit = defineEmits<{ 'update:modelValue': [value: number] }>()

function step(delta: number): void {
  const next = Math.min(props.max, Math.max(props.min, props.modelValue + delta))
  if (next !== props.modelValue) emit('update:modelValue', next)
}
</script>

<template>
  <div class="flex items-center justify-between gap-4 rounded-lg border border-line bg-surface-2 px-3 py-2.5">
    <div class="min-w-0">
      <p class="text-sm font-medium text-content">{{ label }}</p>
      <p class="mt-0.5 text-xs text-subtle">
        {{ hint ?? `До ${max} ${plural(max, unit[0], unit[1], unit[2])} в одни руки` }}
      </p>
    </div>

    <div class="flex flex-none items-center gap-1">
      <button
        type="button"
        :disabled="modelValue <= min"
        :class="
          cn(
            'grid h-9 w-9 place-items-center rounded-md border border-line text-lg leading-none transition-colors',
            'hover:bg-surface-3 disabled:cursor-not-allowed disabled:opacity-35',
          )
        "
        aria-label="Уменьшить"
        @click="step(-1)"
      >−</button>

      <output class="w-9 text-center text-base font-semibold tabular-nums text-content">{{ modelValue }}</output>

      <button
        type="button"
        :disabled="modelValue >= max"
        :class="
          cn(
            'grid h-9 w-9 place-items-center rounded-md border border-line text-lg leading-none transition-colors',
            'hover:bg-surface-3 disabled:cursor-not-allowed disabled:opacity-35',
          )
        "
        aria-label="Увеличить"
        @click="step(1)"
      >+</button>
    </div>
  </div>
</template>
