<script setup lang="ts">
import { computed, useId } from 'vue'
import { cn } from '@/lib/cn'

const props = defineProps<{
  modelValue: boolean
  label?: string
  description?: string
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
const id = useId()
const describedBy = computed(() => (props.description ? `${id}-desc` : undefined))
</script>

<template>
  <div class="flex gap-2.5">
    <span class="relative flex h-5 flex-none items-center">
      <input
        :id="id"
        type="checkbox"
        :checked="modelValue"
        :disabled="disabled"
        :aria-describedby="describedBy"
        :class="
          cn(
            'peer h-5 w-5 cursor-pointer appearance-none rounded border border-line-strong bg-surface',
            'transition-colors duration-120',
            'checked:border-brand-500 checked:bg-brand-500',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40',
            'disabled:cursor-not-allowed disabled:opacity-50',
          )
        "
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      />
      <span
        aria-hidden="true"
        class="pointer-events-none absolute left-1/2 top-1/2 hidden -translate-x-1/2 -translate-y-1/2 text-[11px] leading-none text-white peer-checked:block"
      >✓</span>
    </span>
    <label v-if="label || description" :for="id" class="cursor-pointer select-none">
      <span class="block text-sm leading-5 text-content">{{ label }}</span>
      <span v-if="description" :id="`${id}-desc`" class="mt-0.5 block text-xs leading-4 text-subtle">{{ description }}</span>
    </label>
  </div>
</template>
