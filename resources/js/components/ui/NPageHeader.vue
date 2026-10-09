<script setup lang="ts">
/**
 * Шапка раздела админки.
 *
 * Один и тот же паттерн — заголовок, подпись и действия справа — повторяется на
 * двадцати экранах. Раньше он копировался простым <h1> + flex, и где-то
 * подпись была `text-muted`, где-то `text-subtle`, а отступы между ними
 * разъезжались. NPageHeader фиксирует типографику и ритм: один заголовок
 * через font-display, одна подпись, одни действия — и раздел выглядит частью
 * одной системы, а не набором страниц разных авторов.
 */
import { useSlots } from 'vue'

withDefaults(
  defineProps<{
    title?: string
    subtitle?: string
    /** Короткая пиктограмма слева от заголовка. */
    icon?: string
  }>(),
  {},
)

const slots = useSlots()
</script>

<template>
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div class="flex min-w-0 items-start gap-3">
      <span
        v-if="icon"
        aria-hidden="true"
        class="mt-0.5 grid h-11 w-11 flex-none place-items-center rounded-xl bg-brand-gradient text-xl font-bold text-white shadow-brand"
      >{{ icon }}</span>
      <div class="min-w-0">
        <p v-if="slots.eyebrow" class="mb-1 text-2xs font-semibold uppercase tracking-wider text-subtle">
          <slot name="eyebrow" />
        </p>
        <h1 v-if="title" class="font-display text-2xl font-bold tracking-tight text-content">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 text-sm text-muted">{{ subtitle }}</p>
        <slot name="meta" />
      </div>
    </div>
    <div v-if="slots.actions" class="flex flex-none flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
