<script setup lang="ts">
/**
 * Оболочка секции витрины.
 *
 * Заголовок, отступы и максимальная ширина живут здесь, а не в каждом
 * виджете: иначе у десяти виджетов получается десять разных отступов.
 * `flush` — для полноширинных блоков (герой, промо-полоса), которым нужен
 * свой фон во всю ширину окна.
 */
withDefaults(
  defineProps<{
    title?: string
    subtitle?: string
    /** Полноширинный блок: без контейнера и горизонтальных отступов. */
    flush?: boolean
    /** Отключить нижний отступ (последняя секция перед подвалом). */
    tight?: boolean
  }>(),
  { flush: false, tight: false },
)
</script>

<template>
  <section :class="['relative', tight ? 'py-6 sm:py-8' : 'py-10 sm:py-14']">
    <div v-if="flush"><slot /></div>
    <div v-else class="mx-auto max-w-content px-4 sm:px-6">
      <header v-if="title || subtitle" class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
          <h2 v-if="title" class="text-balance text-2xl font-bold tracking-tight text-content sm:text-3xl">
            {{ title }}
          </h2>
          <p v-if="subtitle" class="mt-1.5 max-w-2xl text-pretty text-sm text-muted sm:text-base">{{ subtitle }}</p>
        </div>
        <div class="flex-none"><slot name="aside" /></div>
      </header>
      <slot />
    </div>
  </section>
</template>
