<script setup lang="ts">
/**
 * Поле ввода.
 *
 * Лейбл всегда виден (скрывать его в placeholder — значит заставлять
 * пользователя держать всё в голове). Ошибка объясняет, что исправить, а не
 * просто «Неверно». Подсказка не спорит с ошибкой: если есть ошибка,
 * показывается только она.
 */
import { computed, useId } from 'vue'
import { cn } from '@/lib/cn'

const props = withDefaults(
  defineProps<{
    modelValue?: string | number
    label?: string
    hint?: string
    error?: string
    placeholder?: string
    type?: 'text' | 'email' | 'tel' | 'password' | 'number' | 'search'
    prefix?: string
    suffix?: string
    icon?: string
    disabled?: boolean
    required?: boolean
    autocomplete?: string
    inputmode?: 'text' | 'email' | 'tel' | 'numeric' | 'search'
  }>(),
  { type: 'text' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const hasError = computed(() => Boolean(props.error))
const describedBy = computed(() => (hasError.value ? `${id}-error` : props.hint ? `${id}-hint` : undefined))
</script>

<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="mb-1.5 block text-sm font-medium text-content">
      {{ label }}
      <span v-if="required" class="text-rose-400" aria-hidden="true">*</span>
    </label>

    <div class="relative">
      <span
        v-if="icon"
        aria-hidden="true"
        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-subtle"
      >{{ icon }}</span>
      <span
        v-else-if="prefix"
        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-subtle"
      >{{ prefix }}</span>

      <input
        :id="id"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :aria-invalid="hasError || undefined"
        :aria-describedby="describedBy"
        :class="
          cn(
            'h-11 w-full rounded-lg border bg-surface text-base text-content',
            'placeholder:text-subtle transition-colors duration-120',
            'focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25',
            'disabled:cursor-not-allowed disabled:opacity-50',
            icon || prefix ? 'pl-10' : 'pl-3.5',
            suffix ? 'pr-12' : 'pr-3.5',
            hasError ? 'border-rose-500 focus:border-rose-400 focus:ring-rose-500/20' : 'border-line',
          )
        "
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      />

      <span v-if="suffix" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-subtle">{{ suffix }}</span>
    </div>

    <p v-if="hasError" :id="`${id}-error`" class="mt-1.5 flex items-start gap-1 text-xs text-rose-400">
      <span aria-hidden="true">!</span><span>{{ error }}</span>
    </p>
    <p v-else-if="hint" :id="`${id}-hint`" class="mt-1.5 text-xs text-subtle">{{ hint }}</p>
  </div>
</template>
