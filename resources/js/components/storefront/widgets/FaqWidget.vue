<script setup lang="ts">
/**
 * Вопросы и ответы.
 *
 * Нативные <details>/<summary>: раскрытие работает без JS, с клавиатуры и
 * читается скринридером. Разворачиваем вручную, чтобы в один момент был
 * открыт один вопрос — длинный список с десятью раскрытыми ответами
 * невозможно читать.
 */
import { computed, ref } from 'vue'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const items = computed(
  () => setting<Array<{ q: string; a: string }>>(props.section.settings, 'items', []),
)

const openIndex = ref<number | null>(0)

function toggle(index: number): void {
  openIndex.value = openIndex.value === index ? null : index
}
</script>

<template>
  <div v-if="items.length" class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
    <div v-for="(item, index) in items" :key="index">
      <h3>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-4 px-4 py-4 text-left transition-colors hover:bg-surface-2"
          :aria-expanded="openIndex === index"
          :aria-controls="`faq-${index}`"
          @click="toggle(index)"
        >
          <span class="text-sm font-medium text-content sm:text-base">{{ item.q }}</span>
          <span
            aria-hidden="true"
            :class="['flex-none text-lg text-subtle transition-transform duration-200', openIndex === index ? 'rotate-45' : '']"
            >+</span
          >
        </button>
      </h3>
      <div
        v-show="openIndex === index"
        :id="`faq-${index}`"
        class="px-4 pb-4 text-pretty whitespace-pre-line text-sm text-muted"
      >
        {{ item.a }}
      </div>
    </div>
  </div>

  <p v-else class="text-sm text-subtle">Добавьте вопросы в конструкторе витрины.</p>
</template>
