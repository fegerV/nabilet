<script setup lang="ts">
/**
 * Выпадающий список — нативный <select>.
 *
 * Это осознанно: на мобильном устройстве нативный пикер — то, что пользователь
 * ожидает увидеть, и его не надо эмулировать. Кастомный дропдаун здесь был бы
 * хуже по доступности и по ощущениям, ради одной и той же задачи.
 */
import { useId } from 'vue'
import { cn } from '@/lib/cn'

export interface Option {
  value: string
  label: string
}

withDefaults(
  defineProps<{
    modelValue?: string
    label?: string
    options: Option[]
    hint?: string
    disabled?: boolean
    size?: 'sm' | 'md'
  }>(),
  { size: 'md' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const id = useId()
</script>

<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="mb-1.5 block text-sm font-medium text-content">{{ label }}</label>
    <div class="relative">
      <select
        :id="id"
        :value="modelValue"
        :disabled="disabled"
        :class="
          cn(
            'w-full appearance-none rounded-lg border border-line bg-surface pl-3.5 pr-10',
            'text-content transition-colors duration-120',
            'focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25',
            'disabled:cursor-not-allowed disabled:opacity-50',
            size === 'sm' ? 'h-9 text-sm' : 'h-11 text-base',
          )
        "
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      >
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
      <span
        aria-hidden="true"
        class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-subtle"
      >▾</span>
    </div>
    <p v-if="hint" class="mt-1.5 text-xs text-subtle">{{ hint }}</p>
  </div>
</template>
