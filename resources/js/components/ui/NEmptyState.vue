<script setup lang="ts">
/**
 * Пустое состояние.
 *
 * Пустой экран — это не «ничего». Это ответ на вопрос «почему здесь пусто и что
 * делать дальше». Поэтому у состояния есть причина и действие; у админки
 * действие почти всегда «создать», у витрины — «сбросить фильтры».
 */
defineProps<{
  title: string
  description?: string
  icon?: string
  actionLabel?: string
}>()

defineEmits<{ action: [] }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <div
      class="mb-4 grid h-14 w-14 place-items-center rounded-2xl border border-line bg-surface-2 text-2xl"
      aria-hidden="true"
    >{{ icon ?? '·' }}</div>
    <h3 class="text-base font-semibold text-content">{{ title }}</h3>
    <p v-if="description" class="mt-1.5 max-w-sm text-sm text-muted text-pretty">{{ description }}</p>
    <slot />
    <button
      v-if="actionLabel"
      type="button"
      class="mt-5 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-400"
      @click="$emit('action')"
    >
      {{ actionLabel }}
    </button>
  </div>
</template>
